<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync;

use Psr\Log\LoggerInterface;

/**
 * Détecte les frontières de tenant dans le parcours et déclenche l'archivage
 * du tenant qui vient d'être intégralement parcouru (#582).
 *
 * ## Pourquoi une frontière suffit
 *
 * Le parcours est ordonné par `(institution_id, id)` : les enseignants d'un même
 * tenant sont donc CONTIGUS. Passer de A à B prouve que A a été intégralement
 * parcouru dans le cycle courant — y compris si ce parcours s'est étalé sur
 * plusieurs passes. C'est ce qui remplace la « passe globale complète » qui
 * n'arrivait plus jamais.
 *
 * ## Garde-fou (R5)
 *
 * Corriger la famine ACTIVE un archivage jusqu'ici inerte. Si une erreur KLASSCI
 * est survenue pour un enseignant du tenant, ses séances n'ont pas pu être
 * confirmées : les archiver reviendrait à les supprimer en masse sur une simple
 * panne réseau. On y renonce pour ce cycle, bruyamment.
 *
 * @see PRODUCTION_STANDARDS.md §5 (méthodes ≤40) · §1.6 D (DI strict)
 */
final class TenantArchiveCoordinator
{
    public function __construct(
        private readonly StaleSeanceArchiver $archiver,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Signale l'entrée dans le tenant d'un enseignant. Si le tenant change, le
     * précédent est clos (donc archivé) avant de poursuivre.
     */
    public function enterTenant(SeanceSyncCycleState $state, int $institutionId, SeanceSyncStats $stats): void
    {
        if ($state->currentInstitutionId !== null && $state->currentInstitutionId !== $institutionId) {
            $this->closeTenant($state, $state->currentInstitutionId, $stats);
        }

        $state->currentInstitutionId = $institutionId;
    }

    /**
     * Flux épuisé : le dernier tenant est clos à son tour et le cycle s'achève.
     * Le tenant clos peut provenir du curseur persisté (passe qui reprend et
     * trouve le flux vide) — c'est le cas nominal d'une population qui vient
     * d'être entièrement parcourue.
     */
    public function closeCycle(SeanceSyncCycleState $state, SeanceSyncStats $stats): void
    {
        if ($state->currentInstitutionId !== null) {
            $this->closeTenant($state, $state->currentInstitutionId, $stats);
        }

        $state->cycleCompleted = true;
    }

    private function closeTenant(SeanceSyncCycleState $state, int $institutionId, SeanceSyncStats $stats): void
    {
        if ($state->isTainted($institutionId)) {
            $stats->tenantsArchiveSkipped++;
            $state->clearTaint($institutionId);

            $this->logger->warning(
                '[SyncKlassciSeances] Archivage renoncé — tenant incomplet suite à une erreur du cycle',
                [
                    'institution_id' => $institutionId,
                    'cycle_started_at' => $state->cycleStartedAt->toIso8601String(),
                ],
            );

            return;
        }

        $this->archiver->archive($institutionId, $state->cycleStartedAt, $stats);
        $stats->tenantsCompleted++;
    }
}
