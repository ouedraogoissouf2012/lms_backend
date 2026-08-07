<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #524 — isolation multi-tenant de la gestion des utilisateurs (§1.3).
 *
 * Un coordinateur/admin de l'institution A ne doit pas pouvoir modifier ou
 * supprimer un utilisateur de l'institution B (IDOR cross-tenant). L'isolation
 * repose sur le route-model binding + global scope `BelongsToInstitution`, le
 * tenant étant résolu par `ResolveInstitution` EXCLUSIVEMENT depuis le Bearer
 * token. On utilise donc un VRAI Bearer token (comme en production) — sans quoi
 * le scope serait no-op et l'isolation invisible.
 *
 * @see app/Http/Controllers/API/AdminController.php
 * @see app/Models/Traits/BelongsToInstitution.php
 */
final class AdminUserTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $instA;
    private Institution $instB;
    private User $coordA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->instA = Institution::factory()->create(['slug' => 'admin-iso-a']);
        $this->instB = Institution::factory()->create(['slug' => 'admin-iso-b']);
        $this->coordA = User::factory()->create([
            'institution_id' => $this->instA->id,
            'role' => 'coordinateur',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('admin-iso-524')->plainTextToken];
    }

    public function test_coordinateur_cannot_update_user_of_another_institution(): void
    {
        $targetB = User::factory()->create([
            'institution_id' => $this->instB->id,
            'role' => 'etudiant',
            'name' => 'Original B',
        ]);

        $this->withHeaders($this->bearer($this->coordA))
            ->putJson("/api/users/{$targetB->id}", ['name' => 'Hijacked'])
            ->assertStatus(404);

        $this->assertSame('Original B', $targetB->fresh()->name);
    }

    public function test_coordinateur_cannot_delete_user_of_another_institution(): void
    {
        $targetB = User::factory()->create([
            'institution_id' => $this->instB->id,
            'role' => 'etudiant',
        ]);

        $this->withHeaders($this->bearer($this->coordA))
            ->deleteJson("/api/users/{$targetB->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('users', ['id' => $targetB->id]);
    }

    public function test_coordinateur_can_update_user_of_own_institution(): void
    {
        $targetA = User::factory()->create([
            'institution_id' => $this->instA->id,
            'role' => 'etudiant',
            'name' => 'Original A',
        ]);

        $this->withHeaders($this->bearer($this->coordA))
            ->putJson("/api/users/{$targetA->id}", ['name' => 'Renamed A'])
            ->assertStatus(200);

        $this->assertSame('Renamed A', $targetA->fresh()->name);
    }
}
