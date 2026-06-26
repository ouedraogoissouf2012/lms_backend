<?php

declare(strict_types=1);

namespace App\Services\Klassci\Auth;

use App\Models\User;
use App\Models\UserClass;
use App\Services\KlassciProxyService;
use Psr\Log\LoggerInterface;

/**
 * Synchronise la classe KLASSCI d'un étudiant dans la base locale au login.
 *
 * Extrait de {@see KlassciUserSynchronizer} (issue #275, §1.1 ≤300 lignes) —
 * comportement inchangé. Un étudiant n'a qu'une seule classe : l'API renvoie
 * `data.classe` (singulier).
 *
 * Robustesse : un échec de sync classe ne doit JAMAIS interrompre le login
 * (try/catch, log, pas de rethrow) — identique à la version d'origine.
 */
final class StudentClassSynchronizer
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sync(User $user, string $klassciToken): void
    {
        try {
            $this->logger->info('Synchronisation des classes pour étudiant', [
                'user_id' => $user->id,
                'email'   => $user->email,
            ]);

            $classe = $this->extractClasseFromDashboard($user, $klassciToken);
            if ($classe === null) {
                return;
            }

            $this->persistStudentClasse($user, $classe);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la synchronisation des classes', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extrait la classe de l'étudiant depuis `me/dashboard` KLASSCI.
     *
     * IMPORTANT: L'API retourne `classe` (singulier), pas `classes` (pluriel) —
     * un étudiant n'a qu'une seule classe.
     *
     * @return array<string, mixed>|null
     */
    private function extractClasseFromDashboard(User $user, string $klassciToken): ?array
    {
        $dashboard = $this->klassciService->requestWithUserToken($klassciToken, 'me/dashboard', 'GET');

        $data = $dashboard['data'] ?? [];
        $classe = is_array($data) && isset($data['classe']) && is_array($data['classe'])
            ? $data['classe']
            : null;

        if ($classe === null) {
            $this->logger->warning("Aucune classe trouvée pour l'étudiant", ['user_id' => $user->id]);

            return null;
        }

        $this->logger->info('Classe KLASSCI récupérée', [
            'user_id'    => $user->id,
            'classe_id'  => $classe['id'] ?? null,
            'classe_nom' => $classe['name'] ?? $classe['libelle'] ?? 'N/A',
        ]);

        return $classe;
    }

    /**
     * Persiste la classe étudiant : DELETE anciennes + CREATE nouvelle.
     *
     * @param  array<string, mixed>  $classe
     */
    private function persistStudentClasse(User $user, array $classe): void
    {
        $user->klassciClasses()->delete();

        $classeId = $classe['id'] ?? null;
        $classeNom = $classe['name'] ?? $classe['libelle'] ?? null;

        UserClass::create([
            'user_id'           => $user->id,
            'klassci_classe_id' => $classeId,
            'classe_nom'        => $classeNom,
            'classe_libelle'    => $classe['libelle'] ?? $classe['name'] ?? null,
            'classe_data'       => $classe,
            'synced_at'         => now(),
        ]);

        $this->logger->info('Classe synchronisée avec succès', [
            'user_id'   => $user->id,
            'classe_id' => $classeId,
        ]);
    }
}
