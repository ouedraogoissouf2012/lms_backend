<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #566 / #571 (point 3) — purge PHYSIQUE délibérée des utilisateurs
 * soft-deleted (droit à l'effacement RGPD).
 *
 * Contrepartie du soft delete : la destruction définitive est un geste explicite,
 * dry-run PAR DÉFAUT (aucune purge sans `--force`), tracée, et bornée par un délai
 * de grâce (`--days`, 30 j par défaut).
 *
 * @see app/Console/Commands/PurgeSoftDeletedUsers.php
 */
final class PurgeSoftDeletedUsersTest extends TestCase
{
    use RefreshDatabase;

    private function softDeletedSince(int $daysAgo): User
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->create(['institution_id' => $institution->id]);
        $user->delete();

        // Antidater la suppression (mass update → pas d'événement modèle).
        User::onlyTrashed()->whereKey($user->id)->update(['deleted_at' => now()->subDays($daysAgo)]);

        return $user;
    }

    public function test_dry_run_is_the_default_and_purges_nothing(): void
    {
        $user = $this->softDeletedSince(40);

        $this->artisan('users:purge-deleted')->assertSuccessful();

        // Toujours présent en base (soft-deleted, non purgé).
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_force_purges_old_soft_deleted_users_and_audits(): void
    {
        $user = $this->softDeletedSince(40);

        $this->artisan('users:purge-deleted --force')->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.purged',
            'auditable_id' => $user->id,
        ]);
    }

    public function test_soft_deletes_within_grace_period_are_kept_even_with_force(): void
    {
        // Supprimé aujourd'hui → dans le délai de grâce de 30 j.
        $user = $this->softDeletedSince(2);

        $this->artisan('users:purge-deleted --force')->assertSuccessful();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_negative_days_does_not_purge_recent_soft_deletes(): void
    {
        // Supprimé aujourd'hui : un --days négatif inverserait le délai de grâce
        // et purgerait ce compte récent. La garde max(0, …) l'en empêche.
        $user = $this->softDeletedSince(1);

        $this->artisan('users:purge-deleted --force --days=-30')->assertSuccessful();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }
}
