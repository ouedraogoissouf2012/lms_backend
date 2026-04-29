<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProxyRouteSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test 1: Unauthenticated users cannot access proxy data routes
     */
    public function test_unauthenticated_cannot_access_proxy_data_routes(): void
    {
        $endpoints = [
            '/api/proxy/structure',
            '/api/proxy/filieres',
            '/api/proxy/niveaux-etudes',
            '/api/proxy/classes',
            '/api/proxy/matieres',
            '/api/proxy/enseignants',
            '/api/proxy/emploi-temps',
            '/api/proxy/evaluations',
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);

            // Must not allow unauthenticated access - should be 401 or similar error
            $this->assertNotEquals(200, $response->status(),
                "Route {$endpoint} should not return 200 for unauthenticated access");
        }
    }

    /**
     * Test 2: Unauthenticated users cannot access test-connection
     */
    public function test_unauthenticated_cannot_access_test_connection(): void
    {
        $response = $this->getJson('/api/proxy/test-connection');

        $this->assertNotEquals(200, $response->status(),
            "test-connection should not return 200 for unauthenticated access");
    }

    /**
     * Test 3: Authenticated student can access proxy data routes
     */
    public function test_authenticated_student_can_access_proxy_data_routes(): void
    {
        $student = User::factory()->create(['role' => 'etudiant']);
        Sanctum::actingAs($student);

        $endpoints = [
            '/api/proxy/structure',
            '/api/proxy/filieres',
            '/api/proxy/niveaux-etudes',
            '/api/proxy/classes',
            '/api/proxy/matieres',
            '/api/proxy/enseignants',
            '/api/proxy/emploi-temps',
            '/api/proxy/evaluations',
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);

            // Should NOT be 401 (unauthenticated) or 403 (forbidden)
            $this->assertNotEquals(401, $response->status(), "Route {$endpoint} should not require more auth");
            $this->assertNotEquals(403, $response->status(), "Route {$endpoint} should allow authenticated student");
        }
    }

    /**
     * Test 4: Student cannot access test-connection (requires admin role)
     */
    public function test_student_cannot_access_test_connection(): void
    {
        $student = User::factory()->create(['role' => 'etudiant']);
        Sanctum::actingAs($student);

        $response = $this->getJson('/api/proxy/test-connection');

        $this->assertEquals(403, $response->status());
    }
}
