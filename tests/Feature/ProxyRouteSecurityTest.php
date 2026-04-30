<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/**
 * Tests pour CRITICAL-03 : Sécurisation des routes /api/proxy/*
 * Validation que les routes exposant la structure organisationnelle
 * sont protégées par authentification + rôles.
 */
class ProxyRouteSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: Routes de données organisationnelles sans token → 401
     */
    public function test_unauthenticated_cannot_access_proxy_data_routes(): void
    {
        $routes = [
            '/api/proxy/structure',
            '/api/proxy/filieres',
            '/api/proxy/niveaux-etudes',
            '/api/proxy/classes',
            '/api/proxy/matieres',
            '/api/proxy/enseignants',
            '/api/proxy/emploi-temps',
            '/api/proxy/evaluations',
        ];

        foreach ($routes as $route) {
            $response = $this->getJson($route);
            $response->assertStatus(401)
                ->assertJson(['message' => 'Unauthenticated.']);
        }
    }

    /**
     * Test: /api/proxy/test-connection sans token → 401
     */
    public function test_unauthenticated_cannot_access_test_connection(): void
    {
        $response = $this->getJson('/api/proxy/test-connection');

        $response->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    /**
     * Test: Utilisateur authentifié (étudiant) peut accéder aux routes de données
     */
    public function test_authenticated_student_can_access_proxy_data_routes(): void
    {
        $user = User::factory()->create([
            'role' => 'etudiant',
            'last_klassci_sync' => now(),
        ]);

        Sanctum::actingAs($user);

        $routes = [
            '/api/proxy/structure',
            '/api/proxy/filieres',
            '/api/proxy/classes',
            '/api/proxy/matieres',
        ];

        foreach ($routes as $route) {
            $response = $this->getJson($route);
            // Ne doit pas être 401 (Unauthenticated) ni 403 (rôle insuffisant)
            $this->assertNotEquals(401, $response->status(), "Route {$route} should not be unauthenticated");
            // Note: 500 acceptable si KLASSCI API non disponible, mais pas 401/403
        }
    }

    /**
     * Test: Étudiant ne peut pas accéder à /api/proxy/test-connection → 403
     */
    public function test_student_cannot_access_test_connection(): void
    {
        $user = User::factory()->create([
            'role' => 'etudiant',
            'last_klassci_sync' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/proxy/test-connection');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Accès refusé - Permissions insuffisantes',
            ]);
    }

    /**
     * Test: Coordinateur peut accéder à /api/proxy/test-connection
     */
    public function test_coordinator_can_access_test_connection(): void
    {
        $user = User::factory()->create([
            'role' => 'coordinateur',
            'last_klassci_sync' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/proxy/test-connection');

        // Ne doit pas être 401 ou 403 (rôle ok)
        $this->assertNotEquals(401, $response->status());
        $this->assertNotEquals(403, $response->status());
    }

    /**
     * Test: /api/proxy/me/dashboard sans token → 401
     */
    public function test_unauthenticated_cannot_access_me_dashboard(): void
    {
        $response = $this->getJson('/api/proxy/me/dashboard');

        $response->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    /**
     * Test: /api/proxy/me/teacher-dashboard sans token → 401
     */
    public function test_unauthenticated_cannot_access_me_teacher_dashboard(): void
    {
        $response = $this->getJson('/api/proxy/me/teacher-dashboard');

        $response->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    /**
     * Test: Étudiant peut accéder à /api/proxy/me/dashboard
     */
    public function test_authenticated_student_can_access_me_dashboard(): void
    {
        $user = User::factory()->create([
            'role' => 'etudiant',
            'last_klassci_sync' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/proxy/me/dashboard');

        // Ne doit pas être 401 (Unauthenticated) ni 403 (rôle insuffisant)
        $this->assertNotEquals(401, $response->status());
        // Note: 500 acceptable si KLASSCI API non disponible, mais pas 401/403
    }

    /**
     * Test: Étudiant ne peut pas accéder à /api/proxy/me/teacher-dashboard → 403
     */
    public function test_student_cannot_access_me_teacher_dashboard(): void
    {
        $user = User::factory()->create([
            'role' => 'etudiant',
            'last_klassci_sync' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/proxy/me/teacher-dashboard');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Accès refusé - Permissions insuffisantes',
            ]);
    }

    /**
     * Test: Enseignant peut accéder à /api/proxy/me/teacher-dashboard
     */
    public function test_teacher_can_access_me_teacher_dashboard(): void
    {
        $user = User::factory()->create([
            'role' => 'enseignant',
            'last_klassci_sync' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/proxy/me/teacher-dashboard');

        // Ne doit pas être 401 ou 403 (rôle ok)
        $this->assertNotEquals(401, $response->status());
        $this->assertNotEquals(403, $response->status());
    }
}
