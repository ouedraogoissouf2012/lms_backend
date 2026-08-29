<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seances;

use App\Models\Institution;
use App\Models\Seance;
use App\Services\Seances\Sync\SeanceSyncStats;
use App\Services\Seances\Sync\StaleSeanceArchiver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Issue #582 — le critère d'archivage n'est plus une liste d'identifiants
 * accumulée en mémoire (intenable quand un cycle s'étale sur plusieurs passes)
 * mais le marquage `synced_at` porté par la séance : « non confirmée depuis le
 * début du cycle courant ».
 */
#[CoversClass(StaleSeanceArchiver::class)]
final class StaleSeanceArchiverTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_archives_active_seance_never_confirmed_by_klassci(): void
    {
        $institution = Institution::factory()->create();
        $stale = Seance::factory()->forInstitution($institution)->create([
            'klassci_seance_id' => 77,
            'is_active' => true,
            'synced_at' => null,
        ]);
        $stats = new SeanceSyncStats;

        $this->archiver()->archive($institution->id, CarbonImmutable::now(), $stats);

        $stale->refresh();
        self::assertFalse($stale->is_active);
        self::assertSame('supprimee_klassci', $stale->archive_reason);
        self::assertSame(1, $stats->seancesArchived);
    }

    public function test_archives_seance_last_confirmed_in_a_previous_cycle(): void
    {
        $institution = Institution::factory()->create();
        $cycleStartedAt = CarbonImmutable::now();
        $stale = Seance::factory()->forInstitution($institution)->create([
            'klassci_seance_id' => 77,
            'is_active' => true,
            'synced_at' => $cycleStartedAt->subHour(),
        ]);
        $stats = new SeanceSyncStats;

        $this->archiver()->archive($institution->id, $cycleStartedAt, $stats);

        self::assertFalse($stale->refresh()->is_active);
        self::assertSame(1, $stats->seancesArchived);
    }

    public function test_seance_confirmed_during_the_current_cycle_is_not_archived(): void
    {
        $institution = Institution::factory()->create();
        $cycleStartedAt = CarbonImmutable::now();
        $confirmed = Seance::factory()->forInstitution($institution)->create([
            'klassci_seance_id' => 77,
            'is_active' => true,
            'synced_at' => $cycleStartedAt->addMinute(),
        ]);
        $stats = new SeanceSyncStats;

        $this->archiver()->archive($institution->id, $cycleStartedAt, $stats);

        self::assertTrue($confirmed->refresh()->is_active);
        self::assertSame(0, $stats->seancesArchived);
    }

    /**
     * Cas limite le plus dangereux : `synced_at` et `cycle_started_at` sont des
     * colonnes `timestamp` (précision SECONDE côté MySQL, et Laravel formate
     * les liaisons Carbon en `Y-m-d H:i:s`). Une passe rapide confirme donc les
     * séances DANS LA MÊME SECONDE que le début du cycle. Si la comparaison
     * était `<=` — ou si les microsecondes atteignaient la requête — chaque
     * séance fraîchement confirmée serait archivée dans la foulée.
     */
    public function test_seance_confirmed_within_the_same_second_as_the_cycle_start_is_not_archived(): void
    {
        $institution = Institution::factory()->create();
        $cycleStartedAt = CarbonImmutable::now();
        $confirmed = Seance::factory()->forInstitution($institution)->create([
            'klassci_seance_id' => 77,
            'is_active' => true,
            'synced_at' => $cycleStartedAt,
        ]);
        $stats = new SeanceSyncStats;

        $this->archiver()->archive($institution->id, $cycleStartedAt, $stats);

        self::assertTrue(
            $confirmed->refresh()->is_active,
            'Une séance confirmée dans la seconde du début de cycle ne doit JAMAIS être archivée.',
        );
        self::assertSame(0, $stats->seancesArchived);
    }

    public function test_archiving_is_scoped_to_the_given_institution_only(): void
    {
        $institutionA = Institution::factory()->create();
        $institutionB = Institution::factory()->create();
        $seanceB = Seance::factory()->forInstitution($institutionB)->create([
            'klassci_seance_id' => 77,
            'is_active' => true,
            'synced_at' => null,
        ]);
        $stats = new SeanceSyncStats;

        // Seule institutionA est clôturée : institutionB n'a pas fini son
        // parcours dans ce cycle et ne doit pas être touchée.
        $this->archiver()->archive($institutionA->id, CarbonImmutable::now(), $stats);

        self::assertTrue($seanceB->refresh()->is_active);
        self::assertSame(0, $stats->seancesArchived);
    }

    private function archiver(): StaleSeanceArchiver
    {
        /** @var LoggerInterface&MockInterface $logger */
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->zeroOrMoreTimes();

        return new StaleSeanceArchiver($logger);
    }
}
