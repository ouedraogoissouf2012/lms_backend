<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seances;

use App\Models\Institution;
use App\Models\Seance;
use App\Services\Seances\Sync\SeanceSyncStats;
use App\Services\Seances\Sync\StaleSeanceArchiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(StaleSeanceArchiver::class)]
final class StaleSeanceArchiverTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_archives_active_seance_absent_from_active_ids(): void
    {
        $institution = Institution::factory()->create();
        $stale = Seance::factory()->forInstitution($institution)->create([
            'klassci_seance_id' => 77,
            'is_active' => true,
        ]);
        $stats = new SeanceSyncStats;

        $this->archiver()->archive([$institution->id => [999]], $stats);

        $stale->refresh();
        self::assertFalse($stale->is_active);
        self::assertSame('supprimee_klassci', $stale->archive_reason);
        self::assertSame(1, $stats->seancesArchived);
    }

    public function test_seance_still_present_in_active_ids_is_not_archived(): void
    {
        $institution = Institution::factory()->create();
        $active = Seance::factory()->forInstitution($institution)->create([
            'klassci_seance_id' => 77,
            'is_active' => true,
        ]);
        $stats = new SeanceSyncStats;

        $this->archiver()->archive([$institution->id => [77]], $stats);

        $active->refresh();
        self::assertTrue($active->is_active);
        self::assertSame(0, $stats->seancesArchived);
    }

    public function test_archiving_is_scoped_to_the_given_institution_only(): void
    {
        $institutionA = Institution::factory()->create();
        $institutionB = Institution::factory()->create();
        $seanceB = Seance::factory()->forInstitution($institutionB)->create([
            'klassci_seance_id' => 77,
            'is_active' => true,
        ]);
        $stats = new SeanceSyncStats;

        // Seule institutionA est dans activeIdsByInstitution : institutionB
        // n'a pas été synchronisée à cette passe et ne doit pas être touchée.
        $this->archiver()->archive([$institutionA->id => []], $stats);

        $seanceB->refresh();
        self::assertTrue($seanceB->is_active);
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
