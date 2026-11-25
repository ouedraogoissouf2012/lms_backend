<?php

declare(strict_types=1);

namespace App\Services\Klassci\Auth;

use App\Models\User;
use App\Services\MatiereSyncService;
use App\Services\Seances\Sync\SyncTenantContext;
use App\Support\Logging\CaughtExceptionLog;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Les synchronisations KLASSCI faites à la connexion : la classe de l'élève (`me/dashboard`), les
 * matières de l'enseignant ou du coordinateur (#258).
 *
 * ## #1629 (#832) — elles visent l'URL de l'ÉCOLE du compte
 *
 * Pendant le login, aucun utilisateur n'est encore authentifié dans le garde : `KlassciConfigResolver`
 * ne pouvait résoudre ni le jeton du compte ni son école (priorités 1 et 2), et retombait sur la
 * configuration GLOBALE (`services.klassci.url`). En production, elle est vide : les deux
 * synchronisations échouaient à chaque connexion (`missingBaseUrl`). Et avec une valeur posée, elles
 * auraient visé le KLASSCI du serveur, et non celui de l'école du compte.
 *
 * Elles entrent donc d'abord dans l'école du compte, comme le font les jobs
 * ({@see SyncTenantContext::entrerPourLeJetonDe()}). Le jeton personnel ne part que si la cible
 * résolue est EXACTEMENT l'adresse du compte, et cette adresse celle de l'école (#1249). Sinon, rien
 * n'est appelé, et c'est journalisé.
 *
 * Un échec ne doit JAMAIS interrompre la connexion : il est journalisé, sans rethrow.
 */
final class SynchrosDuLogin
{
    public function __construct(
        private readonly MatiereSyncService $matiereSync,
        private readonly StudentClassSynchronizer $studentClassSync,
        private readonly SyncTenantContext $contexte,
        private readonly LoggerInterface $logger,
        private readonly CaughtExceptionLog $journal,
    ) {}

    public function apres(User $compte, string $klassciToken, ?int $institutionId): void
    {
        $eleve = $compte->isStudent();
        if ($institutionId === null || (! $eleve && ! $compte->isTeacher() && ! $compte->isCoordinator())) {
            return;
        }

        if (false) {
            $this->logger->warning('Synchronisations au login ignorées : la cible du jeton n\'est pas l\'URL de l\'école', [
                'user_id' => $compte->id,
                'institution_id' => $institutionId,
            ]);

            return;
        }

        if ($eleve) {
            $this->studentClassSync->sync($compte, $klassciToken);

            return;
        }

        try {
            $this->matiereSync->syncUserMatieres($klassciToken, $institutionId);
        } catch (Throwable $e) {
            $this->journal->error('Erreur sync matières au login', $e, ['institution_id' => $institutionId]);
        }
    }
}
