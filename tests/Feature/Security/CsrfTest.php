<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de sécurité — CSRF / authentification stateless (#212).
 *
 * L'API est stateless : protégée par token Sanctum (Bearer), pas par session
 * + cookie CSRF. La protection anti-CSRF découle de ce design — une requête
 * sans token valide est rejetée (401), donc un site tiers ne peut pas agir
 * au nom de l'utilisateur via ses cookies (il n'y en a pas).
 *
 * Ces tests AFFIRMENT : toute route protégée exige un token, un token absent
 * ou invalide est refusé, et les opérations d'écriture ne sont JAMAIS
 * accessibles sans authentification.
 *
 * @see routes/api.php (middleware auth:sanctum)
 * @see config/sanctum.php
 */
final class CsrfTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
        $this->user = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
    }

    /**
     * Toutes les routes de lecture protégées exigent un token (401 sans).
     */
    public function test_protected_read_routes_require_token(): void
    {
        $routes = [
            '/api/lessons',
            '/api/quizzes',
            '/api/forum/topics',
            '/api/notifications',
            '/api/notifications/unread-count',
        ];

        foreach ($routes as $route) {
            $response = $this->getJson($route);
            $this->assertSame(
                401,
                $response->status(),
                "La route {$route} est accessible sans authentification — faille"
            );
        }
    }

    /**
     * Les écritures (POST/PUT/DELETE) sont refusées sans token — un site
     * tiers ne peut donc rien déclencher au nom de l'utilisateur.
     */
    public function test_write_routes_reject_unauthenticated_requests(): void
    {
        $this->postJson('/api/forum/topics', [
            'title' => 'Topic via CSRF',
            'content' => 'Tentative de création sans authentification valide.',
        ])->assertStatus(401);

        $this->postJson('/api/lessons', [
            'title' => 'Cours via CSRF',
            'type' => 'cours',
            'classe_id' => 1,
        ])->assertStatus(401);

        $this->deleteJson('/api/forum/topics/1')->assertStatus(401);
    }

    /**
     * Un token malformé / invalide est rejeté (401), jamais accepté.
     */
    public function test_invalid_bearer_token_is_rejected(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ceci-est-un-faux-token-invalide-1234567890',
        ])->getJson('/api/lessons');

        $response->assertStatus(401);
    }

    /**
     * Un vrai token Sanctum donne accès aux routes protégées.
     */
    public function test_valid_token_grants_access(): void
    {
        $token = $this->user->createToken('test-token')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/lessons')
            ->assertStatus(200);
    }

    /**
     * Un token révoqué (supprimé) ne donne plus accès.
     *
     * Note : la révocation est faite AVANT tout appel authentifié dans ce
     * test, pour éviter l'artefact de cache du guard auth (l'utilisateur
     * résolu lors d'un 1er appel resterait en mémoire pour la durée du test).
     */
    public function test_revoked_token_is_rejected(): void
    {
        $token = $this->user->createToken('test-token')->plainTextToken;

        // Révocation immédiate : le token n'existe plus en base.
        $this->user->tokens()->delete();

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/lessons')
            ->assertStatus(401);
    }

    /**
     * Le endpoint de login lui-même est public mais throttlé (anti-bruteforce)
     * et ne nécessite pas de token (sinon impossible de se connecter).
     */
    public function test_login_endpoint_is_public_but_rejects_bad_credentials(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'inconnu@example.com',
            'password' => 'mauvais-mot-de-passe',
        ]);

        // Pas de 401 "token manquant" (route publique), mais échec d'auth
        // propre (401/422), jamais un 200 ni un 500.
        $this->assertContains($response->status(), [401, 422]);
    }
}
