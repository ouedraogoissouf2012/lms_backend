<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Institution;
use App\Models\User;
use App\Services\Auth\LocalLmsAuthenticator;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Issue #566 / #571 — interactions du soft delete avec les flux d'authentification.
 *
 * L'ajout du trait `SoftDeletes` active le `SoftDeletingScope`, qui reste actif même
 * derrière `withoutGlobalScope('institution')`. Deux conséquences à verrouiller :
 *
 *  - `LocalLmsAuthenticator` ne trouve plus un compte soft-deleted → login local refusé
 *    (comportement SOUHAITÉ, R3).
 *  - `KlassciUserSynchronizer` ne trouverait plus un compte soft-deleted → il ferait un
 *    INSERT et violerait `users_klassci_id_institution_id_unique`. Le correctif restaure
 *    la ligne via `withTrashed()` (R5).
 *
 * @see app/Services/Auth/LocalLmsAuthenticator.php
 * @see app/Services/Klassci/Auth/KlassciUserSynchronizer.php
 */
final class SoftDeletedUserAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_authenticator_refuses_soft_deleted_user(): void
    {
        $institution = Institution::factory()->create(['slug' => 'local-auth-566']);
        $user = User::factory()->create([
            'institution_id' => $institution->id,
            'email' => 'ghost@example.test',
            'password' => bcrypt('secret-pass'),
            'role' => 'etudiant',
        ]);

        $authenticator = app(LocalLmsAuthenticator::class);

        // Sanity : avant suppression, le login local réussit.
        $this->assertInstanceOf(
            User::class,
            $authenticator->attemptLocalAuth('ghost@example.test', 'secret-pass')
        );

        $user->delete();

        // R3 : un compte soft-deleted n'est plus authentifiable localement.
        $this->assertNull($authenticator->attemptLocalAuth('ghost@example.test', 'secret-pass'));
    }

    public function test_klassci_resync_restores_soft_deleted_user_without_duplicate(): void
    {
        // Aucune sortie réseau : neutralise une éventuelle branche de sync matières.
        Http::fake();

        $institution = Institution::factory()->create(['slug' => 'resync-566']);
        $user = User::factory()->create([
            'institution_id' => $institution->id,
            'klassci_id' => 4242,
            'email' => 'resync@example.test',
            // 'admin' évite les branches student/teacher de doSync() → 0 appel HTTP.
            'role' => 'admin',
        ]);

        $user->delete();
        $this->assertSoftDeleted('users', ['id' => $user->id]);

        $synchronizer = app(KlassciUserSynchronizer::class);
        $restored = $synchronizer->sync(
            ['id' => 4242, 'email' => 'resync@example.test', 'nom' => 'Resync', 'role' => 'admin'],
            'klassci-token-abc',
            'https://resync-566.klassci.test',
            $institution,
        );

        // R5 : la MÊME ligne est restaurée — pas de doublon, pas de violation d'unicité.
        $this->assertSame($user->id, $restored->id);
        $this->assertFalse($restored->trashed());
        $this->assertNull($restored->fresh()?->deleted_at);
        $this->assertSame(1, User::withoutGlobalScope('institution')
            ->withTrashed()
            ->where('klassci_id', 4242)
            ->where('institution_id', $institution->id)
            ->count());
    }
}
