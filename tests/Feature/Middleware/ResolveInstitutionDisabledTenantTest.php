<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * #565 (P0 de #563) — Institution désactivée → fuite cross-tenant (fail-open).
 *
 * Racine : `ResolveInstitution::resolveFromBearerToken` ne posait pas le tenant
 * quand l'institution du porteur est `is_active=false` (ou introuvable) et
 * laissait la requête suivre son cours. Comme `BelongsToInstitution` est
 * fail-open (log + skip du scope) et que ~57 services ne filtrent jamais par
 * `institution_id`, la requête devenait non scopée → lecture cross-tenant.
 *
 * Correctif : la voie bearer devient FAIL-SECURE (403) pour une institution
 * désactivée/introuvable, tout en préservant supradmin (institution_id null),
 * le chemin nominal (institution active) et le 401 des tokens invalides.
 *
 * ⚠️ Les tests envoient un VRAI bearer token (`createToken`) et non
 * `Sanctum::actingAs`, car `actingAs` contourne le middleware (aucun bearer
 * token → voie Priority 3, `resolveFromBearerToken` jamais exécutée).
 *
 * @see app/Http/Middleware/ResolveInstitution.php
 * @see app/Models/Traits/BelongsToInstitution.php
 */
final class ResolveInstitutionDisabledTenantTest extends TestCase
{
    use RefreshDatabase;

    private Institution $activeTenant;
    private Institution $disabledTenant;
    private User $userOfDisabledTenant;
    private string $bearerOfDisabledTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activeTenant = Institution::factory()->create(['slug' => 'active-tenant']);
        $this->disabledTenant = Institution::factory()->create(['slug' => 'disabled-tenant']);

        // Un utilisateur de chaque tenant → il existe des données d'un AUTRE
        // tenant (activeTenant) susceptibles de fuiter.
        $this->userOfDisabledTenant = User::factory()->create([
            'institution_id' => $this->disabledTenant->id,
            'role' => 'etudiant',
        ]);
        User::factory()->create([
            'institution_id' => $this->activeTenant->id,
            'role' => 'etudiant',
        ]);

        $this->bearerOfDisabledTenant = $this->userOfDisabledTenant
            ->createToken('test')->plainTextToken;

        // Suspension de l'établissement APRÈS émission du token (impayé,
        // résiliation…). Le token reste techniquement valide.
        $this->disabledTenant->is_active = false;
        $this->disabledTenant->save();
    }

    /**
     * Route « sonde » — échafaudage de TEST uniquement (jamais dans `routes/`).
     *
     * Elle lit un modèle globale-scopé (`User`) et NE FILTRE PAS explicitement
     * par `institution_id` : son isolation dépend ENTIÈREMENT du scope global
     * `BelongsToInstitution`, exactement comme les ~57 services vulnérables.
     * Montée dans le groupe `api` (qui porte `ResolveInstitution` en prepend)
     * + `auth:sanctum`, elle exerce le vrai seam middleware → scope.
     */
    private function registerTenantScopedProbeRoute(): void
    {
        Route::middleware(['api', 'auth:sanctum'])->get(
            '/_probe/tenant-scoped-read',
            static fn () => response()->json([
                'institution_ids' => User::query()
                    ->pluck('institution_id')
                    ->unique()
                    ->values()
                    ->all(),
            ])
        );
    }

    /**
     * RED→GREEN — cœur de #565 : un porteur d'institution désactivée ne doit
     * lire AUCUNE donnée d'un autre tenant. Avant le fix, cette requête
     * renvoyait 200 avec les `institution_id` de l'autre tenant (fuite).
     *
     * _Requirements: R1_
     */
    public function test_disabled_institution_bearer_cannot_read_cross_tenant_rows(): void
    {
        $this->registerTenantScopedProbeRoute();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->bearerOfDisabledTenant)
            ->getJson('/_probe/tenant-scoped-read');

        // Fail-secure : refus AVANT tout accès aux données.
        $response->assertStatus(403);

        // Défense : aucun identifiant d'un autre tenant ne remonte au client.
        $this->assertNotContains(
            $this->activeTenant->id,
            (array) $response->json('institution_ids'),
            'FUITE CROSS-TENANT : institution désactivée lit les données d\'un autre tenant'
        );
    }

    /**
     * Le refus fail-secure vaut aussi sur une route de PRODUCTION (toutes les
     * routes `api` portent `ResolveInstitution` en prepend global).
     *
     * _Requirements: R1_
     */
    public function test_disabled_institution_bearer_is_refused_on_real_endpoint(): void
    {
        $this->disableKlassciMiddleware();

        $this->withHeader('Authorization', 'Bearer '.$this->bearerOfDisabledTenant)
            ->getJson('/api/notifications')
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    /**
     * R2 — un porteur rattaché à une institution INTROUVABLE (supprimée
     * physiquement, cf. P0-4) ne doit jamais tourner non scopé.
     *
     * _Requirements: R2_
     */
    public function test_bearer_user_bound_to_missing_institution_is_refused_403(): void
    {
        // #583 : la FK institution_id rend un id inexistant impossible en base.
        // On reproduit l'état « institution non résoluble » via un soft-delete
        // (#567) : la ligne existe (FK satisfaite) mais Institution::find() la
        // masque → le middleware doit toujours refuser (403), défense en profondeur.
        $ghost = Institution::factory()->create();
        $orphan = User::factory()->create(['institution_id' => $ghost->id]);
        $token = $orphan->createToken('test')->plainTextToken;
        $ghost->delete();

        $this->registerTenantScopedProbeRoute();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/_probe/tenant-scoped-read')
            ->assertStatus(403);
    }

    /**
     * R3 — chemin nominal : institution active → tenant posé → scope appliqué
     * (seul l'id de l'institution du porteur remonte).
     *
     * _Requirements: R3_
     */
    public function test_active_institution_bearer_resolves_and_scopes(): void
    {
        $userActive = User::factory()->create(['institution_id' => $this->activeTenant->id]);
        $token = $userActive->createToken('test')->plainTextToken;

        $this->registerTenantScopedProbeRoute();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/_probe/tenant-scoped-read');

        $response->assertStatus(200);
        $this->assertEquals(
            [$this->activeTenant->id],
            $response->json('institution_ids'),
            'Tenant actif résolu → le scope global doit filtrer sur la seule institution du porteur'
        );
    }

    /**
     * R4 — anti-régression CRITIQUE : le supradmin (institution_id null) voit
     * volontairement tous les tenants et NE DOIT PAS être refusé.
     *
     * _Requirements: R4_
     */
    public function test_supradmin_null_institution_is_not_refused(): void
    {
        $supradmin = User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
        ]);
        $token = $supradmin->createToken('test')->plainTextToken;

        $this->registerTenantScopedProbeRoute();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/_probe/tenant-scoped-read')
            ->assertStatus(200);
    }

    /**
     * R6 — anti-régression : un token invalide reste un 401 (auth:sanctum), pas
     * un 403. Le middleware ne doit pas confondre « token invalide » et
     * « institution désactivée ».
     *
     * _Requirements: R6_
     */
    public function test_invalid_bearer_token_still_returns_401(): void
    {
        $this->registerTenantScopedProbeRoute();

        $this->withHeader('Authorization', 'Bearer invalid-token-value')
            ->getJson('/_probe/tenant-scoped-read')
            ->assertStatus(401);
    }

    /**
     * R5 — anti-régression : la voie header (sans bearer) conserve son 400
     * historique pour une institution inactive.
     *
     * _Requirements: R5_
     */
    public function test_header_path_inactive_institution_still_returns_400(): void
    {
        $this->registerTenantScopedProbeRoute();

        $this->withHeader('X-Institution', $this->disabledTenant->slug)
            ->getJson('/_probe/tenant-scoped-read')
            ->assertStatus(400);
    }
}
