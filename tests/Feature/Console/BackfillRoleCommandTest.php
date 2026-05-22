<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #126 — Tests Feature pour `klassci:backfill-role`.
 *
 * Couvre l'extraction du backfill `klassci_role` de la migration #118 vers
 * un command artisan dédié. Le command doit reproduire EXACTEMENT la logique
 * de la migration originale (idempotence, filtre, sémantique déterministe).
 *
 * Spec: `.claude/specs/backfill-command-artisan/`
 *
 * @see \App\Console\Commands\Klassci\BackfillRoleCommand
 */
final class BackfillRoleCommandTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('PostgreSQL PDO driver not available (CI-only test).');
        }

        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
    }

    /**
     * Crée un user et force `klassci_role = null` (la factory `UserFactory` par
     * défaut crée des users sans cette colonne, mais on s'assure de l'état initial
     * pour tester le backfill).
     */
    private function userWithRole(string $role, ?int $klassciId = 100): User
    {
        $user = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role'           => $role,
            'klassci_id'     => $klassciId,
        ]);

        // Force klassci_role à null via raw update pour simuler l'état pre-#118.
        DB::table('users')->where('id', $user->id)->update(['klassci_role' => null]);

        return $user->fresh();
    }

    public function test_backfill_copies_role_to_klassci_role_for_synced_users(): void
    {
        $student     = $this->userWithRole('etudiant', klassciId: 100);
        $teacher     = $this->userWithRole('enseignant', klassciId: 200);
        $coordinator = $this->userWithRole('coordinateur', klassciId: 300);

        $this->artisan('klassci:backfill-role')
            ->assertExitCode(0);

        self::assertSame('etudiant',     User::find($student->id)->klassci_role);
        self::assertSame('enseignant',   User::find($teacher->id)->klassci_role);
        self::assertSame('coordinateur', User::find($coordinator->id)->klassci_role);
    }

    public function test_backfill_skips_users_without_klassci_id(): void
    {
        $localUser = $this->userWithRole('supradmin', klassciId: null);

        $this->artisan('klassci:backfill-role')
            ->assertExitCode(0);

        self::assertNull(User::find($localUser->id)->klassci_role);
    }

    public function test_backfill_is_idempotent(): void
    {
        $teacher = $this->userWithRole('enseignant', klassciId: 200);

        // Run 1 — backfill
        $this->artisan('klassci:backfill-role')->assertExitCode(0);
        self::assertSame('enseignant', User::find($teacher->id)->klassci_role);

        // Run 2 — re-run sur l'état post-backfill : doit produire le même résultat
        // (sémantique `klassci_role = role` déterministe, idempotence par valeur).
        $this->artisan('klassci:backfill-role')->assertExitCode(0);
        self::assertSame('enseignant', User::find($teacher->id)->klassci_role);
    }

    public function test_dry_run_does_not_write_to_db(): void
    {
        $teacher = $this->userWithRole('enseignant', klassciId: 200);

        $this->artisan('klassci:backfill-role', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run]')
            ->assertExitCode(0);

        self::assertNull(
            User::find($teacher->id)->klassci_role,
            'klassci_role MUST remain null after --dry-run.',
        );
    }

    public function test_rejects_invalid_chunk_size_below_min(): void
    {
        $this->artisan('klassci:backfill-role', ['--chunk' => 0])
            ->expectsOutputToContain('between 1 and 10000')
            ->assertExitCode(1);
    }

    public function test_rejects_invalid_chunk_size_above_max(): void
    {
        $this->artisan('klassci:backfill-role', ['--chunk' => 1000000])
            ->expectsOutputToContain('between 1 and 10000')
            ->assertExitCode(1);
    }
}
