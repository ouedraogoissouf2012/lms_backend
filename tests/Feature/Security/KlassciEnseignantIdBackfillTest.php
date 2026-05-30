<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #119 — verifies the migration backfill of `klassci_enseignant_id`
 * from the existing `klassci_data['enseignant_id']` blob.
 *
 * Tests run the actual migration in isolation (rollback → re-apply) rather
 * than re-implementing its logic — this catches regressions if the migration
 * code drifts away from what the tests assume.
 *
 * Spec: `.claude/specs/klassci-enseignant-id-separation/`
 *
 * @see database/migrations/2026_05_19_000001_add_klassci_enseignant_id_to_users_table.php
 */
final class KlassciEnseignantIdBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {

        parent::setUp();
    }

    /**
     * Re-execute the backfill migration: rollback drops the column + its index,
     * re-apply recreates them AND runs the backfill closure on the existing rows.
     * Any user with `klassci_data['enseignant_id']` is expected to receive the
     * matching value in the column.
     *
     * Cible explicitement le batch de la migration backfill (vs `--step 1`)
     * pour résister aux migrations ultérieures qui s'intercaleraient (ex:
     * PERF-05 cleanup `@temp.local` du 2026-05-30).
     */
    private function rerunBackfillMigration(): void
    {
        Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_05_19_000001_add_klassci_enseignant_id_to_users_table.php']);
        Artisan::call('migrate');
    }

    public function test_migration_backfill_copies_enseignant_id_from_blob(): void
    {
        $institution = Institution::factory()->create(['slug' => 'school-a']);

        // Simulate a user whose blob carries enseignant_id but whose
        // dedicated column is NULL (pre-migration state, or backfilled
        // column subsequently nulled by an admin tool).
        $user = User::factory()->create([
            'institution_id'        => $institution->id,
            'klassci_id'            => 5000,
            'role'                  => 'enseignant',
            'klassci_data'          => json_encode(['enseignant_id' => 42]),
        ]);
        DB::table('users')->where('id', $user->id)->update(['klassci_enseignant_id' => null]);

        $this->rerunBackfillMigration();

        self::assertSame(42, (int) $user->fresh()->klassci_enseignant_id);
    }

    public function test_backfill_leaves_null_when_blob_has_no_enseignant_id(): void
    {
        $institution = Institution::factory()->create(['slug' => 'school-b']);

        $user = User::factory()->create([
            'institution_id'        => $institution->id,
            'klassci_id'            => 6000,
            'role'                  => 'etudiant',
            'klassci_data'          => json_encode(['etudiant_id' => 12]),  // no enseignant_id
        ]);
        DB::table('users')->where('id', $user->id)->update(['klassci_enseignant_id' => null]);

        $this->rerunBackfillMigration();

        self::assertNull($user->fresh()->klassci_enseignant_id);
    }
}
