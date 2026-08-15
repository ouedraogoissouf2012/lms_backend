<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #567 / #572 (R6) — purge PHYSIQUE délibérée des institutions soft-deletées.
 *
 * Dry-run PAR DÉFAUT. `institution_id` n'ayant AUCUNE FK, un `forceDelete` d'une
 * institution encore peuplée créerait des orphelins : la purge REFUSE tant que des
 * utilisateurs subsistent. Tracée, non planifiée.
 *
 * @see app/Console/Commands/PurgeSoftDeletedInstitutions.php
 */
final class PurgeSoftDeletedInstitutionsTest extends TestCase
{
    use RefreshDatabase;

    private function softDeletedInstitution(int $daysAgo): Institution
    {
        $institution = Institution::factory()->inactive()->create();
        $institution->delete();

        // Antidater la suppression au-delà du délai de grâce (mass update, pas d'événement).
        Institution::onlyTrashed()->whereKey($institution->id)->update(['deleted_at' => now()->subDays($daysAgo)]);

        return $institution;
    }

    public function test_dry_run_is_the_default_and_purges_nothing(): void
    {
        $institution = $this->softDeletedInstitution(40);

        $this->artisan('institutions:purge-deleted')->assertSuccessful();

        $this->assertSoftDeleted('institutions', ['id' => $institution->id]);
    }

    public function test_force_refuses_to_purge_institution_with_remaining_users(): void
    {
        $institution = $this->softDeletedInstitution(40);
        User::factory()->create(['institution_id' => $institution->id]);

        $this->artisan('institutions:purge-deleted --force')->assertSuccessful();

        // Refus : des utilisateurs subsistent → purger orphelinerait leurs données.
        $this->assertSoftDeleted('institutions', ['id' => $institution->id]);
    }

    public function test_force_refuses_to_purge_institution_with_non_user_children(): void
    {
        $institution = $this->softDeletedInstitution(40);
        // Aucun utilisateur, mais une classe subsiste → le garde élargi doit refuser.
        Classe::factory()->create(['institution_id' => $institution->id]);

        $this->artisan('institutions:purge-deleted --force')->assertSuccessful();

        $this->assertSoftDeleted('institutions', ['id' => $institution->id]);
    }

    public function test_force_purges_institution_without_children(): void
    {
        $institution = $this->softDeletedInstitution(40);

        $this->artisan('institutions:purge-deleted --force')->assertSuccessful();

        $this->assertDatabaseMissing('institutions', ['id' => $institution->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'institution.purged',
            'auditable_id' => $institution->id,
        ]);
    }
}
