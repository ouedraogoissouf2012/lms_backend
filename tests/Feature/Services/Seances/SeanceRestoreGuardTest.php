<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Seances;

use App\Models\Institution;
use App\Models\Seance;
use App\Services\Seances\SeanceRestoreGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #542 — verrouille le contrat de `SeanceRestoreGuard::restoreIfTrashed()`
 * directement (jusqu'ici couvert uniquement indirectement via les tests
 * feature de `KlassciSeancesSyncService`/`VisioToggleService` — audit
 * `spec-architect`, recommandation LOW).
 */
final class SeanceRestoreGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_restores_a_trashed_seance_and_resets_business_archive_state(): void
    {
        $institution = Institution::factory()->create();
        $seance = Seance::factory()->forInstitution($institution)->create([
            'is_active' => false,
            'archived_at' => now()->subDay(),
            'archive_reason' => 'supprimee_klassci',
        ]);
        $seance->delete();
        self::assertTrue($seance->trashed());

        SeanceRestoreGuard::restoreIfTrashed($seance);

        self::assertFalse($seance->trashed());
        self::assertNull($seance->deleted_at);
        self::assertTrue((bool) $seance->is_active);
        self::assertNull($seance->archived_at);
        self::assertNull($seance->archive_reason);
    }

    public function test_is_a_safe_no_op_on_a_non_trashed_seance_no_query_issued(): void
    {
        $institution = Institution::factory()->create();
        $seance = Seance::factory()->forInstitution($institution)->create([
            'is_active' => true,
        ]);
        self::assertFalse($seance->trashed());

        DB::enableQueryLog();
        SeanceRestoreGuard::restoreIfTrashed($seance);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        self::assertSame(
            [],
            $queries,
            'Un appel sur une séance non trashed ne doit émettre AUCUNE requête (no-op) — ne doit jamais désarchiver une séance active-mais-archivée trouvée hors d\'un chemin de restauration réel.',
        );
        self::assertTrue((bool) $seance->is_active);
    }
}
