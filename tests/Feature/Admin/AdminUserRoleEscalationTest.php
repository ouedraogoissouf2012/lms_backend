<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de non-régression sécurité (audit 2026-07-02 — OWASP A01).
 *
 * Vérifie de bout en bout que la règle {@see \App\Rules\AssignableRole}, câblée
 * dans {@see \App\Http\Requests\CreateUserRequest} et
 * {@see \App\Http\Requests\UpdateUserRequest}, bloque l'escalade verticale :
 * un coordinateur ne peut ni créer un `superAdmin`, ni se promouvoir lui-même.
 *
 * @see app/Http/Controllers/API/AdminController.php
 */
final class AdminUserRoleEscalationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
        $this->coordinator = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role'           => 'coordinateur',
        ]);
        Sanctum::actingAs($this->coordinator);
    }

    public function test_coordinator_cannot_create_a_superadmin(): void
    {
        $response = $this->postJson('/api/users', [
            'name'                  => 'Pirate',
            'email'                 => 'pirate@example.test',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'superAdmin',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'pirate@example.test']);
    }

    public function test_coordinator_cannot_create_another_coordinator(): void
    {
        // Moindre privilège strict : pas de création d'un pair de même niveau.
        $response = $this->postJson('/api/users', [
            'name'                  => 'Pair',
            'email'                 => 'pair@example.test',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'coordinateur',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_coordinator_cannot_self_promote_to_superadmin(): void
    {
        $response = $this->putJson('/api/users/' . $this->coordinator->id, [
            'role' => 'superAdmin',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('role');
        $this->assertSame('coordinateur', $this->coordinator->fresh()->role);
    }

    public function test_coordinator_can_still_create_a_lower_role(): void
    {
        // Happy path : le durcissement ne casse pas le flux légitime.
        $response = $this->postJson('/api/users', [
            'name'                  => 'Nouvel Enseignant',
            'email'                 => 'enseignant@example.test',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'enseignant',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'enseignant@example.test',
            'role'  => 'enseignant',
        ]);
    }
}
