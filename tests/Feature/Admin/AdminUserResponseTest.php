<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Caractérisation (axe #1) — fige la forme JSON EXACTE des 3 endpoints de
 * {@see \App\Http\Controllers\API\AdminController} AVANT la migration vers le
 * trait {@see \App\Http\Controllers\Concerns\RespondsWithJson}.
 *
 * Contrats actuels :
 *   - createUser : 201 `{ success, message, data }`
 *   - updateUser : 200 `{ success, message, data }`
 *   - deleteUser : 200 `{ success, message }` (aucune clé `data`)
 *
 * Auth : `Sanctum::actingAs` d'un coordinateur (passe le middleware
 * `role:coordinateur,superAdmin` ET l'`authorize()` des FormRequests, lequel
 * exige `isCoordinator() || isAdmin()`). Le middleware `klassci.sync` est
 * désactivé (non pertinent ici).
 *
 * @see app/Http/Controllers/API/AdminController.php
 */
final class AdminUserResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $institution = Institution::factory()->create(['slug' => 'school-a']);
        Sanctum::actingAs(User::factory()->create([
            'institution_id' => $institution->id,
            'role'           => 'coordinateur',
        ]));
    }

    public function test_create_user_returns_success_message_data_envelope(): void
    {
        $response = $this->postJson('/api/users', [
            'name'                  => 'Nouvel Enseignant',
            'email'                 => 'nouvel.enseignant@example.test',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'enseignant',
        ]);

        $response->assertStatus(201);

        $json = $response->json();
        $this->assertSame(['success', 'message', 'data'], array_keys($json));
        $this->assertTrue($json['success']);
        $this->assertSame('Utilisateur créé avec succès', $json['message']);
        $this->assertSame('nouvel.enseignant@example.test', $json['data']['email']);
    }

    public function test_update_user_returns_success_message_data_envelope(): void
    {
        $target = User::factory()->create([
            'role' => 'etudiant',
            'name' => 'Ancien Nom',
        ]);

        $response = $this->putJson('/api/users/' . $target->id, [
            'name' => 'Nom Modifié',
        ]);

        $response->assertStatus(200);

        $json = $response->json();
        $this->assertSame(['success', 'message', 'data'], array_keys($json));
        $this->assertTrue($json['success']);
        $this->assertSame('Utilisateur mis à jour', $json['message']);
        $this->assertSame('Nom Modifié', $json['data']['name']);
    }

    public function test_delete_user_returns_success_message_envelope(): void
    {
        $target = User::factory()->create(['role' => 'etudiant']);

        $response = $this->deleteJson('/api/users/' . $target->id);

        // Forme déterministe (aucune donnée) → assertion exacte.
        $response->assertStatus(200)->assertExactJson([
            'success' => true,
            'message' => 'Utilisateur supprimé',
        ]);
    }
}
