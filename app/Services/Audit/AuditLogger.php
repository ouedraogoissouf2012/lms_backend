<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\User;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Point d'entrée unique pour écrire dans le journal d'audit (#215).
 *
 * Capture « qui / quoi / quand / depuis où » :
 *   - acteur : le compte passé explicitement (connexion, déconnexion), sinon
 *     l'utilisateur authentifié courant (null si échec d'auth / job) ;
 *   - provenance : IP + user-agent de la requête courante (null hors HTTP) ;
 *   - école : celle de l'acteur ; à défaut d'acteur rattaché à une école
 *     (supradmin, job système), celle de la cible (voir `write()`).
 *
 * DI strict §1.6 : AuthFactory, Request et ConfigRepository injectés — aucun
 * helper global `auth()` / `request()` / `config()`. Testable en isolation.
 *
 * @see app/Observers/AuditableObserver.php (CREATE/UPDATE/DELETE)
 * @see app/Http/Controllers/API/AuthController.php (déconnexion)
 * @see app/Services/Auth/LoginOrchestrator.php (connexions)
 */
final class AuditLogger
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Request $request,
        private readonly ConfigRepository $config,
        private readonly CaughtExceptionLog $journal,
    ) {}

    /**
     * Enregistre une action sur un modèle auditable (CREATE/UPDATE/DELETE).
     *
     * @param  array<string, mixed>|null  $before  État avant (null pour create).
     * @param  array<string, mixed>|null  $after  État après (null pour delete).
     */
    public function logModelEvent(string $action, Model $model, ?array $before, ?array $after): void
    {
        $this->write($action, [
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'before' => $before,
            'after' => $after,
        ], cible: $model);
    }

    /**
     * Trace une mise à jour : l'avant et l'après des seuls attributs modifiés,
     * hors `hidden` (aucun secret journalisé). Rien si seuls des `hidden` ont
     * changé. C'est la SEULE source de cette forme (B3) : l'observateur l'appelle
     * à l'événement `updated`, et une écriture conditionnelle qui ne passe pas par
     * le modèle (RemiseDeCopie) l'appelle après une transition gagnée.
     *
     * La trace dit ce qui a été ÉCRIT (D515). Une colonne dotée d'un accesseur de
     * lecture s'y lit donc par sa valeur STOCKÉE : `EvaluationSubmission::status`
     * déduit `verrouille` d'une copie échue encore stockée `en_cours`, et la trace
     * mentirait sinon. Les casts restent appliqués à toutes les autres colonnes.
     *
     * @param  list<string>  $cles  les attributs modifiés ; `getOriginal()` y porte
     *                               encore l'avant (avant `syncOriginal()`)
     */
    public function logModelUpdate(Model $model, array $cles): void
    {
        $caches = $model->getHidden();
        $before = [];
        $after = [];
        foreach ($cles as $cle) {
            if (in_array($cle, $caches, true)) {
                continue;
            }
            $stockee = $model->hasAttributeGetMutator($cle) || $model->hasGetMutator($cle);
            $before[$cle] = $stockee ? $model->getRawOriginal($cle) : $model->getOriginal($cle);
            $after[$cle] = $stockee ? ($model->getAttributes()[$cle] ?? null) : $model->getAttribute($cle);
        }

        if ($after === []) {
            return;
        }

        $this->logModelEvent('update', $model, $before, $after);
    }

    /**
     * Enregistre un événement d'authentification (login/logout/login_failed).
     *
     * @param  User|null  $acteur  Le compte qui se connecte ou se déconnecte.
     *                             Au login, le garde n'est pas encore
     *                             authentifié : c'est ce compte qui donne
     *                             l'école de l'entrée. Null pour un login
     *                             échoué, dont le compte n'est pas identifié.
     * @param  array<string, mixed>|null  $context  Métadonnées additionnelles.
     */
    public function logAuthEvent(string $action, ?User $acteur = null, ?array $context = null): void
    {
        $this->write($action, [
            'after' => $context,
        ], $acteur);
    }

    /**
     * Enregistre un événement métier sensible hors CRUD automatique.
     *
     * @param  array<string, mixed>|null  $context
     */
    public function logSecurityEvent(string $action, ?Model $target = null, ?array $context = null): void
    {
        $this->write($action, [
            'auditable_type' => $target?->getMorphClass(),
            'auditable_id' => $target?->getKey(),
            'after' => $context,
        ], cible: $target);
    }

    /**
     * Écriture bas-niveau, append-only.
     *
     * L'école de l'entrée est celle de l'ACTEUR (explicite, sinon celui du
     * garde). À défaut d'acteur rattaché à une école (supradmin, job système),
     * c'est celle de la CIBLE : le supradmin qui diagnostique cherche l'école
     * visée. La cible n'écrase jamais l'école d'un acteur qui en a une.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function write(string $action, array $attributes, ?User $acteur = null, ?Model $cible = null): void
    {
        if (! $this->config->get('audit.enabled', true)) {
            return;
        }

        // #241 : l'audit est transversal — un échec d'écriture (table absente,
        // DB momentanément indisponible…) ne doit JAMAIS casser l'action métier
        // qui l'a déclenché (notamment le login). On loggue l'échec et on
        // continue : la traçabilité est importante mais secondaire devant la
        // disponibilité de l'opération auditée.
        try {
            $garde = $this->auth->guard()->user();
            $acteur ??= $garde instanceof User ? $garde : null;
            // L'auteur et son école viennent du même compte : jamais l'id du garde
            // avec l'école d'un acteur explicite.
            $userId = $acteur !== null ? $acteur->getKey() : $garde?->getAuthIdentifier();
            $institutionId = $this->ecoleDe($acteur) ?? $this->ecoleDe($cible);

            AuditLog::create(array_merge([
                'user_id' => $userId,
                'institution_id' => $institutionId,
                'action' => $action,
                'ip_address' => $this->request->ip(),
                'user_agent' => substr((string) $this->request->userAgent(), 0, 512),
            ], $attributes));
        } catch (\Throwable $e) {
            $this->journal->warning('Échec écriture audit log — action métier préservée', $e, [
                'audit_action' => $action,
            ]);
        }
    }

    /** L'école d'un modèle : son id pour une école, son `institution_id` sinon. */
    private function ecoleDe(?Model $modele): ?int
    {
        if ($modele instanceof Institution) {
            $id = $modele->getKey();

            return is_numeric($id) ? (int) $id : null;
        }

        $ecole = $modele?->getAttribute('institution_id');

        return is_numeric($ecole) ? (int) $ecole : null;
    }
}
