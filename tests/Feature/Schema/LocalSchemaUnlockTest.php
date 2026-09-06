<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Models\UserClass;
use App\Services\Seances\Sync\SeanceSyncStats;
use App\Services\Seances\Sync\StaleSeanceArchiver;
use App\Services\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #710 — donnée locale insérable ; colonnes klassci_* nulles inertes pour la sync.
 */
final class LocalSchemaUnlockTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_local_seance_evaluation_and_classe_are_insertable(): void
    {
        $institution = Institution::factory()->create();
        app(TenantManager::class)->set($institution);

        $seance = Seance::factory()->create([
            'institution_id' => $institution->id,
            'klassci_seance_id' => null,
        ]);
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $institution->id,
            'klassci_matiere_id' => null,
            'klassci_classe_id' => null,
        ]);
        $classe = Classe::factory()->create([
            'institution_id' => $institution->id,
            'klassci_id' => null,
        ]);

        $this->assertDatabaseHas('seances', ['id' => $seance->id, 'klassci_seance_id' => null]);
        $this->assertDatabaseHas('evaluations', ['id' => $evaluation->id, 'klassci_matiere_id' => null]);
        $this->assertDatabaseHas('classes', ['id' => $classe->id, 'klassci_id' => null]);
    }

    public function test_klassci_rows_keep_their_ids(): void
    {
        $institution = Institution::factory()->create();
        app(TenantManager::class)->set($institution);
        $seance = Seance::factory()->create(['institution_id' => $institution->id]);

        self::assertNotNull($seance->fresh()->klassci_seance_id);
    }

    public function test_user_classes_rejects_null_klassci_classe_id(): void
    {
        $institution = Institution::factory()->create();
        app(TenantManager::class)->set($institution);
        $user = User::factory()->for($institution)->create();

        $this->expectException(QueryException::class);
        UserClass::query()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
            'klassci_classe_id' => null,
            'classe_nom' => 'x',
        ]);
    }

    public function test_stale_archiver_ignores_local_seances(): void
    {
        $institution = Institution::factory()->create();
        app(TenantManager::class)->set($institution);
        $local = Seance::factory()->create([
            'institution_id' => $institution->id,
            'klassci_seance_id' => null,
            'is_active' => true,
            'synced_at' => null,
        ]);
        $stats = new SeanceSyncStats;
        app(StaleSeanceArchiver::class)->archive($institution->id, now(), $stats);

        self::assertTrue((bool) $local->fresh()->is_active);
        self::assertSame(0, $stats->seancesArchived);
    }

    public function test_teacher_can_create_local_seance_via_http(): void
    {
        $this->disableKlassciMiddleware();
        $institution = Institution::factory()->create();
        $teacher = User::factory()->teacher()->for($institution)->create([
            'last_klassci_sync' => now(),
        ]);

        $this->asTenant($teacher)
            ->postJson('/api/lms/seances', [
                'titre' => 'Cours local autonome',
                'date_seance' => now()->addDay()->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.klassci_seance_id', null);

        $this->assertDatabaseHas('seances', [
            'titre' => 'Cours local autonome',
            'created_by' => $teacher->id,
            'klassci_seance_id' => null,
        ]);
    }

    public function test_golden_master_nullability(): void
    {
        $nullable = static fn (string $table, string $column): bool => collect(Schema::getColumns($table))
            ->firstWhere('name', $column)['nullable'] ?? false;

        self::assertTrue($nullable('evaluations', 'klassci_matiere_id'));
        self::assertTrue($nullable('evaluations', 'klassci_classe_id'));
        self::assertTrue($nullable('classes', 'klassci_id'));
        self::assertTrue($nullable('seances', 'klassci_seance_id'));
        self::assertFalse($nullable('user_classes', 'klassci_classe_id'));
    }
}
