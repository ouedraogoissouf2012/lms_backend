<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Point d'entrée unique pour écrire dans le journal d'audit (#215).
 *
 * Capture « qui / quoi / quand / depuis où » :
 *   - acteur : utilisateur authentifié courant (null si échec d'auth / job) ;
 *   - provenance : IP + user-agent de la requête courante (null hors HTTP) ;
 *   - tenant : institution de l'acteur (null pour supradmin / système).
 *
 * DI strict §1.6 : AuthFactory, Request et ConfigRepository injectés — aucun
 * helper global `auth()` / `request()` / `config()`. Testable en isolation.
 *
 * @see app/Observers/AuditableObserver.php (CREATE/UPDATE/DELETE)
 * @see app/Http/Controllers/API/AuthController.php (events auth)
 */
final class AuditLogger
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Request $request,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Enregistre une action sur un modèle auditable (CREATE/UPDATE/DELETE).
     *
     * @param array<string, mixed>|null $before État avant (null pour create).
     * @param array<string, mixed>|null $after  État après (null pour delete).
     */
    public function logModelEvent(string $action, Model $model, ?array $before, ?array $after): void
    {
        $this->write($action, [
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'before' => $before,
            'after' => $after,
        ]);
    }

    /**
     * Enregistre un événement d'authentification (login/logout/login_failed).
     *
     * @param int|null $userId Acteur explicite (un login échoué n'a pas de user
     *                         authentifié ; on passe l'id résolu si connu).
     * @param array<string, mixed>|null $context Métadonnées additionnelles.
     */
    public function logAuthEvent(string $action, ?int $userId = null, ?array $context = null): void
    {
        $this->write($action, [
            'after' => $context,
        ], $userId);
    }

    /**
     * Écriture bas-niveau, append-only.
     *
     * @param array<string, mixed> $attributes
     */
    private function write(string $action, array $attributes, ?int $explicitUserId = null): void
    {
        if (! $this->config->get('audit.enabled', true)) {
            return;
        }

        $user = $this->auth->guard()->user();
        $userId = $explicitUserId ?? $user?->getAuthIdentifier();
        $institutionId = $user instanceof User ? $user->institution_id : null;

        AuditLog::create(array_merge([
            'user_id' => $userId,
            'institution_id' => $institutionId,
            'action' => $action,
            'ip_address' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 512),
        ], $attributes));
    }
}
