<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #568 (P0 de #563) — Isolation par utilisateur de GET /api/auth/me.
 *
 * `AuthController::me()` faisait `KlassciProxyService::get('auth/me')`, dont la
 * clé de cache est GLOBALE au tenant (`generateGlobalKey`, sans hash du token).
 * Or `KlassciConfigResolver` résout `auth/me` avec le token PERSONNEL
 * (priorité 1) : la réponse est liée à l'identité du porteur. Conséquence : le
 * profil du 1er appelant était mis en cache sous une clé partagée et servi à
 * TOUT le tenant → fuite d'identité.
 *
 * ## Vecteur reproduit
 *
 * On feinte UNIQUEMENT la frontière réseau ({@see KlassciHttpClient}) — tout le
 * reste est réel : `KlassciProxyService`, la stratégie de clés, le cache
 * distribué (store `database` en test) et le memo intra-request. Entre les deux
 * requêtes on vide les instances `scoped` (memo, tenant) pour simuler deux
 * cycles de requête distincts comme en production : le SEUL état partagé restant
 * est le cache distribué — exactement le vecteur de la fuite.
 */
final class AuthMePerUserIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<string, mixed>> Profil KLASSCI par token porteur. */
    private const PROFILES = [
        'token-alice' => ['id' => 1, 'nom' => 'Alice'],
        'token-bob'   => ['id' => 2, 'nom' => 'Bob'],
    ];

    public function test_two_users_of_same_tenant_do_not_share_auth_me_profile(): void
    {
        $tenantUrl = 'https://klassci.tenant.test';
        $institution = Institution::factory()->create(['klassci_api_url' => $tenantUrl]);

        $alice = User::factory()->for($institution)->create([
            'klassci_token'      => 'token-alice',
            'klassci_tenant_url' => $tenantUrl,
        ]);
        $bob = User::factory()->for($institution)->create([
            'klassci_token'      => 'token-bob',
            'klassci_tenant_url' => $tenantUrl,
        ]);

        $this->fakeKlassciTransportReturningTokenOwnerProfile();

        // Alice appelle en premier → peuple le cache.
        Sanctum::actingAs($alice);
        $aliceResponse = $this->getJson('/api/auth/me');
        $aliceResponse->assertOk();
        self::assertSame('Alice', $aliceResponse->json('data.klassci_data.nom'));

        // Nouveau cycle de requête (memo/tenant réinitialisés) : seul le cache
        // distribué persiste — comme entre deux requêtes HTTP réelles.
        $this->simulateFreshRequestBoundary();

        // Bob appelle ensuite → DOIT recevoir SON profil, jamais celui d'Alice.
        Sanctum::actingAs($bob);
        $bobResponse = $this->getJson('/api/auth/me');
        $bobResponse->assertOk();
        self::assertSame(
            'Bob',
            $bobResponse->json('data.klassci_data.nom'),
            'Bob a reçu le profil KLASSCI d\'Alice — fuite d\'identité cross-user via le cache global.',
        );
        self::assertSame(2, $bobResponse->json('data.klassci_data.id'));
    }

    public function test_user_without_personal_token_gets_empty_klassci_profile(): void
    {
        $tenantUrl = 'https://klassci.tenant.test';
        $institution = Institution::factory()->create(['klassci_api_url' => $tenantUrl]);

        $withToken = User::factory()->for($institution)->create([
            'klassci_token'      => 'token-alice',
            'klassci_tenant_url' => $tenantUrl,
        ]);
        $withoutToken = User::factory()->for($institution)->create([
            'klassci_token'      => null,
            'klassci_tenant_url' => $tenantUrl,
        ]);

        $this->fakeKlassciTransportReturningTokenOwnerProfile();

        // Un utilisateur avec token peuple d'abord le cache global (ancien vecteur).
        Sanctum::actingAs($withToken);
        $this->getJson('/api/auth/me')->assertOk();

        $this->simulateFreshRequestBoundary();

        // Le compte sans token personnel ne doit hériter d'AUCUN profil d'autrui.
        Sanctum::actingAs($withoutToken);
        $response = $this->getJson('/api/auth/me');
        $response->assertOk();
        self::assertSame([], $response->json('data.klassci_data'));
    }

    /**
     * Edge case : une panne KLASSCI (5xx) est avalée par le helper → réponse
     * dégradée gracieusement (200 + profil local, `klassci_data` vide), jamais
     * de 500 ni de détail d'erreur exposé. Couvre la branche `catch → []`.
     */
    public function test_klassci_failure_degrades_to_empty_profile(): void
    {
        $tenantUrl = 'https://klassci.tenant.test';
        $institution = Institution::factory()->create(['klassci_api_url' => $tenantUrl]);
        $user = User::factory()->for($institution)->create([
            'klassci_token'      => 'token-alice',
            'klassci_tenant_url' => $tenantUrl,
        ]);

        $factory = new HttpFactory();
        $factory->fake(fn () => Http::response(['message' => 'upstream boom'], 500));
        $this->app->instance(HttpFactory::class, $factory);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/auth/me');

        $response->assertOk();
        self::assertSame([], $response->json('data.klassci_data'));
        // Le profil local reste servi ; aucun détail d'erreur KLASSCI n'est exposé.
        self::assertSame($user->id, $response->json('data.id'));
    }

    /**
     * Feinte de la SEULE frontière réseau : on remplace la `Http\Client\Factory`
     * injectée dans le vrai `KlassciHttpClient` (DIP) par une factory `fake()` —
     * aucun curl, aucun transport réel. KLASSCI renvoie le profil du PORTEUR du
     * token : le `ConfigResolver` réel attache le token personnel (priorité 1)
     * en `Authorization: Bearer`, que la factory lit pour router le profil.
     */
    private function fakeKlassciTransportReturningTokenOwnerProfile(): void
    {
        $factory = new HttpFactory();
        $factory->fake(function (ClientRequest $request) {
            $bearer = $request->header('Authorization')[0] ?? '';
            $token = str_replace('Bearer ', '', $bearer);

            return Http::response(['data' => ['user' => self::PROFILES[$token] ?? []]]);
        });

        $this->app->instance(HttpFactory::class, $factory);
    }

    /**
     * Réinitialise les singletons `scoped` (memo intra-request, tenant) pour
     * simuler la frontière entre deux requêtes HTTP distinctes. Le fake
     * transport (binding `instance`) et le cache distribué survivent.
     */
    private function simulateFreshRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();
    }
}
