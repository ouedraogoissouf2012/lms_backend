<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seances\Sync;

use App\Services\Seances\Sync\Cursor\SeanceSyncPosition;
use App\Services\Seances\Sync\SeanceSyncCycleState;
use App\Services\Seances\Sync\SeanceSyncStats;
use App\Services\Seances\Sync\StaleSeanceArchiverInterface;
use App\Services\Seances\Sync\TenantArchiveCoordinator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Le garde de souillure est la SEULE protection contre un archivage de masse.
 *
 * ## Pourquoi ce test existe
 *
 * `StaleSeanceArchiver` archive toute séance non confirmée depuis le début du
 * cycle. Si une panne KLASSCI empêche la confirmation, le critère devient vrai
 * pour l'établissement ENTIER : sans garde, une coupure réseau de quelques
 * minutes désactiverait toutes les séances de l'école.
 *
 * {@see TenantArchiveCoordinator} est ce garde. Il était jusqu'ici sans test
 * dédié, alors qu'un second archiveur non gardé (`CleanObsoleteSeances`, #516)
 * coexistait. Ce dernier ayant été retiré, la totalité du risque d'archivage
 * passe désormais par ce seul point — d'où sa couverture explicite.
 *
 * ## Pourquoi un faux plutôt qu'un mock
 *
 * L'assertion porte sur un COMPORTEMENT — « l'archiveur n'est pas appelé » —
 * pas sur une séquence d'appels. Un faux qui compte ses invocations l'exprime
 * sans coupler le test à l'implémentation (§1.6, substituabilité LSP).
 *
 * @see PRODUCTION_STANDARDS.md §1.3 (tests obligatoires) · §1.6 (SOLID)
 * @see \Tests\Feature\Seances\NoPhantomKlassciSeanceProbeTest
 */
final class TenantArchiveCoordinatorTaintGuardTest extends TestCase
{
    private const INSTITUTION_A = 1;

    private const INSTITUTION_B = 2;

    public function test_a_tainted_tenant_is_never_archived_when_its_traversal_closes(): void
    {
        $archiver = $this->spyArchiver();
        $state = $this->freshCycle();
        $stats = new SeanceSyncStats();

        $state->currentInstitutionId = self::INSTITUTION_A;
        // Une erreur KLASSCI est survenue pour un enseignant de ce tenant.
        $state->taint(self::INSTITUTION_A);

        $this->coordinator($archiver)->closeCycle($state, $stats);

        self::assertSame([], $archiver->archivedInstitutionIds);
        self::assertSame(1, $stats->tenantsArchiveSkipped);
        self::assertSame(0, $stats->tenantsCompleted);
    }

    public function test_an_intact_tenant_is_archived_when_its_traversal_closes(): void
    {
        $archiver = $this->spyArchiver();
        $state = $this->freshCycle();
        $stats = new SeanceSyncStats();

        $state->currentInstitutionId = self::INSTITUTION_A;

        $this->coordinator($archiver)->closeCycle($state, $stats);

        self::assertSame([self::INSTITUTION_A], $archiver->archivedInstitutionIds);
        self::assertSame(1, $stats->tenantsCompleted);
        self::assertSame(0, $stats->tenantsArchiveSkipped);
    }

    /**
     * La souillure d'un tenant ne doit pas contaminer le suivant : le parcours
     * est ordonné par institution, et une panne chez A ne dit rien de B.
     */
    public function test_taint_on_one_tenant_does_not_block_archiving_of_the_next(): void
    {
        $archiver = $this->spyArchiver();
        $state = $this->freshCycle();
        $stats = new SeanceSyncStats();
        $coordinator = $this->coordinator($archiver);

        $coordinator->enterTenant($state, self::INSTITUTION_A, $stats);
        $state->taint(self::INSTITUTION_A);

        // Franchir la frontière clôt A (souillé → renoncé) et ouvre B.
        $coordinator->enterTenant($state, self::INSTITUTION_B, $stats);
        $coordinator->closeCycle($state, $stats);

        self::assertSame([self::INSTITUTION_B], $archiver->archivedInstitutionIds);
        self::assertSame(1, $stats->tenantsArchiveSkipped);
        self::assertSame(1, $stats->tenantsCompleted);
    }

    /**
     * Sans tenant courant (cycle vide), rien n'est archivé — le cycle se clôt
     * néanmoins, sinon le curseur ne repartirait jamais de zéro.
     */
    public function test_closing_an_empty_cycle_archives_nothing_but_completes(): void
    {
        $archiver = $this->spyArchiver();
        $state = $this->freshCycle();
        $stats = new SeanceSyncStats();

        $this->coordinator($archiver)->closeCycle($state, $stats);

        self::assertSame([], $archiver->archivedInstitutionIds);
        self::assertTrue($state->cycleCompleted);
    }

    private function coordinator(StaleSeanceArchiverInterface $archiver): TenantArchiveCoordinator
    {
        return new TenantArchiveCoordinator($archiver, $this->logger());
    }

    private function freshCycle(): SeanceSyncCycleState
    {
        return SeanceSyncCycleState::resume(
            SeanceSyncPosition::startOfCycle(CarbonImmutable::parse('2026-08-30 00:00:00')),
        );
    }

    private function logger(): LoggerInterface
    {
        return new NullLogger();
    }

    /**
     * Faux d'archiveur : enregistre les institutions archivées, ne touche à
     * aucune base. Substituable à la vraie implémentation (LSP).
     */
    private function spyArchiver(): StaleSeanceArchiverInterface
    {
        return new class implements StaleSeanceArchiverInterface {
            /** @var array<int, int> */
            public array $archivedInstitutionIds = [];

            public function archive(int $institutionId, CarbonInterface $cycleStartedAt, SeanceSyncStats $stats): void
            {
                $this->archivedInstitutionIds[] = $institutionId;
            }
        };
    }
}
