<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync;

use Carbon\CarbonInterface;

/**
 * Archive les séances d'un tenant que KLASSCI n'a pas confirmées durant le cycle.
 *
 * ## Pourquoi une interface pour un seul implémenteur
 *
 * Depuis le retrait de `CleanObsoleteSeances` (#516), l'archivage de séances
 * passe par un unique chemin. Son garde — {@see TenantArchiveCoordinator}, qui
 * refuse d'archiver un tenant dont le cycle a subi une erreur — est donc la
 * SEULE protection contre une désactivation de masse sur simple panne réseau.
 *
 * Vérifier ce garde suppose de pouvoir observer que l'archiveur n'est PAS
 * appelé. L'implémentation concrète étant `final` et frappant la base, elle
 * n'est pas substituable : sans cette abstraction, la protection la plus
 * critique du mécanisme resterait non testable (§1.6 D/LSP).
 *
 * @see PRODUCTION_STANDARDS.md §1.6 (DIP : dépendre d'une abstraction)
 */
interface StaleSeanceArchiverInterface
{
    /**
     * N'archive que dans l'institution donnée — jamais de balayage global.
     */
    public function archive(int $institutionId, CarbonInterface $cycleStartedAt, SeanceSyncStats $stats): void;
}
