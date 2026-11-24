<?php

declare(strict_types=1);

namespace App\Services\Klassci\Auth;

use App\Models\User;
use App\Models\UserClass;
use App\Services\KlassciProxyService;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Synchronise la classe KLASSCI d'un étudiant dans la base locale au login.
 *
 * Extrait de {@see KlassciUserSynchronizer} (issue #275, §1.1 ≤300 lignes). Un
 * étudiant n'a qu'une seule classe : l'API renvoie `data.classe` (singulier).
 *
 * ## Ne jamais laisser l'élève sans sa classe précédente (#969)
 *
 * La nouvelle classe est ÉCRITE avant que les autres soient effacées, dans une
 * transaction : une écriture en échec n'efface plus rien. Les sorties sont
 * distinguées ({@see SynchroDeLaClasse}) et journalisées chacune à son niveau ;
 * seule une classe reçue et bien formée touche à `user_classes`.
 *
 * Robustesse : un échec ne doit JAMAIS interrompre le login (try/catch, journal,
 * pas de rethrow).
 *
 * ## Le miroir de la classe (#886)
 *
 * Une classe écrite sans miroir local dans `classes` est demandée à
 * {@see MiroirDeLaClasseDeLEleve}, qui passe par le job existant : ce service
 * n'écrit jamais `classes` (ADR-878-01, ADR-886-01).
 */
final class StudentClassSynchronizer
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly LoggerInterface $logger,
        private readonly CaughtExceptionLog $journal,
        private readonly ConnectionInterface $db,
        private readonly MiroirDeLaClasseDeLEleve $miroir,
    ) {}

    public function sync(User $user, string $klassciToken): SynchroDeLaClasse
    {
        // Un compte sans école (le supradmin, seul depuis #801) n'a aucune école à donner à
        // la ligne : il ne reçoit pas de classe KLASSCI, et KLASSCI n'est pas appelé.
        if (false) {
            return SynchroDeLaClasse::SansClasse;
        }

        try {
            $dashboard = $this->klassciService->requestWithUserToken($klassciToken, 'me/dashboard', 'GET');
            $data = $dashboard['data'] ?? null;

            if (is_array($data) && array_key_exists('classe', $data) && $data['classe'] === null) {
                $this->logger->info('Élève sans classe chez KLASSCI', ['user_id' => $user->id]);

                return SynchroDeLaClasse::SansClasse;
            }

            $classe = is_array($data) && is_array($data['classe'] ?? null) ? $data['classe'] : null;
            $id = $classe['id'] ?? null;
            if ($classe === null || ! is_numeric($id)) {
                // Les seules CLÉS reçues : jamais les valeurs d'un payload d'élève.
                $this->logger->warning('Réponse KLASSCI inattendue pour la classe de l\'élève', [
                    'user_id' => $user->id,
                    'cles' => is_array($data) ? array_keys($data) : [],
                    'cles_classe' => $classe === null ? [] : array_keys($classe),
                ]);

                return SynchroDeLaClasse::ReponseInattendue;
            }

            $this->remplacerLaClasse($user, (int) $id, $classe);
            $this->logger->info('Classe KLASSCI de l\'élève synchronisée', ['user_id' => $user->id, 'classe_id' => (int) $id]);
            $this->miroir->demanderSiAbsent($user, (int) $id);

            return SynchroDeLaClasse::Ecrite;
        } catch (\Exception $e) {
            $this->journal->error('Échec de la synchronisation de la classe de l\'élève', $e, [
                'user_id' => $user->id,
            ]);

            return SynchroDeLaClasse::Echec;
        }
    }

    /**
     * Écrit la classe, PUIS efface les autres lignes de l'élève, ensemble : une
     * panne entre les deux ne lui laisse ni zéro ni deux classes.
     *
     * @param  array<mixed>  $classe
     */
    private function remplacerLaClasse(User $user, int $klassciClasseId, array $classe): void
    {
        $this->db->transaction(function () use ($user, $klassciClasseId, $classe): void {
            UserClass::query()->updateOrCreate(
                ['user_id' => $user->id, 'klassci_classe_id' => $klassciClasseId],
                [
                    // EXPLICITE, et non delegue au trait (#878). `BelongsToInstitution`
                    // pose cette colonne a la creation depuis le tenant resolu — mais
                    // ce code tourne PENDANT le login, ou l utilisateur n est pas encore
                    // authentifie et ou aucun en-tete n a ete exige : `ResolveInstitution`
                    // n a rien pose, et le trait passe en `log-and-no-op`.
                    //
                    // La colonne restait donc nulle, et le scope global de `UserClass`
                    // — qui filtre precisement dessus — rendait AVEUGLES les cinq
                    // lecteurs de cette table. Mesure du 21/09.
                    //
                    // `KlassciUserSynchronizer:157` fait deja ainsi pour le compte
                    // lui-meme : on aligne les deux freres.
                    'institution_id' => $user->institution_id,
                    'classe_nom' => $classe['name'] ?? $classe['libelle'] ?? null,
                    'classe_libelle' => $classe['libelle'] ?? $classe['name'] ?? null,
                    'classe_data' => $classe,
                    'synced_at' => now(),
                ],
            );

            $user->klassciClasses()->where('klassci_classe_id', '!=', $klassciClasseId)->delete();
        });
    }
}
