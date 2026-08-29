<?php

declare(strict_types=1);

namespace Tests\Feature\Visio;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\SeanceRecording;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #549 — SeanceRecording est scopé par institution.
 */
final class SeanceRecordingTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_recordings_from_another_tenant_are_invisible(): void
    {
        $schoolA = Institution::factory()->create();
        $schoolB = Institution::factory()->create();
        $seanceA = Seance::factory()->create(['institution_id' => $schoolA->id]);
        $seanceB = Seance::factory()->create(['institution_id' => $schoolB->id]);
        SeanceRecording::factory()->forSeance($seanceA)->create();
        SeanceRecording::factory()->forSeance($seanceB)->create();

        app(TenantManager::class)->set($schoolA);

        self::assertSame(1, SeanceRecording::query()->count());
        self::assertTrue(
            SeanceRecording::query()->where('seance_id', $seanceA->id)->exists()
        );
        self::assertFalse(
            SeanceRecording::query()->where('seance_id', $seanceB->id)->exists()
        );
    }
}
