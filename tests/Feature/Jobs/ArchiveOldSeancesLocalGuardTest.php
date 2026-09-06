<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ArchiveOldSeances;
use App\Models\Institution;
use App\Models\Seance;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #704 — l'archivage d'âge ne détruit pas les séances locales.
 */
final class ArchiveOldSeancesLocalGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_local_seance_is_never_age_archived(): void
    {
        $inst = Institution::factory()->create();
        app(TenantManager::class)->set($inst);

        $local = Seance::factory()->create([
            'institution_id' => $inst->id,
            'klassci_seance_id' => null,
            'is_active' => true,
            'date_seance' => now()->subWeeks(3),
            'created_at' => now()->subWeeks(3),
        ]);

        $this->app->call([new ArchiveOldSeances, 'handle']);

        $this->assertTrue((bool) $local->fresh()->is_active);
        $this->assertNull($local->fresh()->archived_at);
    }

    public function test_future_date_seance_is_not_archived_even_if_created_at_is_old(): void
    {
        $inst = Institution::factory()->create();
        app(TenantManager::class)->set($inst);

        $seance = Seance::factory()->create([
            'institution_id' => $inst->id,
            'is_active' => true,
            'date_seance' => now()->addWeek(),
            'created_at' => now()->subWeeks(3),
        ]);

        $this->app->call([new ArchiveOldSeances, 'handle']);

        $this->assertTrue((bool) $seance->fresh()->is_active);
    }

    public function test_null_date_seance_falls_back_to_created_at(): void
    {
        $inst = Institution::factory()->create();
        app(TenantManager::class)->set($inst);

        $seance = Seance::factory()->create([
            'institution_id' => $inst->id,
            'is_active' => true,
            'date_seance' => null,
            'created_at' => now()->subWeeks(3),
        ]);

        $this->app->call([new ArchiveOldSeances, 'handle']);

        $fresh = $seance->fresh();
        $this->assertFalse((bool) $fresh->is_active);
        $this->assertSame('trop_ancienne', $fresh->archive_reason);
    }
}
