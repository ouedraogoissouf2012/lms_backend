<?php

declare(strict_types=1);

namespace Tests\Feature\Proxy;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #591 (P1 de #563) — Isolation par porteur de `/api/proxy/evaluations`
 * et `/api/proxy/emploi-temps`.
 *
 * `HasKlassciEndpointShortcuts::getEvaluations()` / `::getEmploiTemps()`
 * passaient par `KlassciProxyService::get()`, dont la clé de cache est GLOBALE
 * au tenant (`generateGlobalKey`, sans hash du porteur). Or
 * `KlassciConfigResolver` attache le jeton PERSONNEL de l'utilisateur connecté
 * (priorité 1) : la réponse est liée à l'identité du porteur. La réponse du 1ᵉʳ
 * appelant était donc stockée sous une clé partagée puis servie à TOUT le
 * tenant — même classe de fuite que #568 (`/auth/me`).
 *
 * Aggravant vérifié : `routes/api/core.php:73-94` place ces deux routes dans un
 * groupe SANS restriction de rôle — un étudiant et un enseignant du même tenant
 * partageaient la même entrée de cache.
 *
 * ## Vecteur reproduit
 *
 * On feinte UNIQUEMENT la frontière réseau : la `Illuminate\Http\Client\Factory`
 * injectée dans le vrai `KlassciHttpClient` (couture DIP). Tout le reste est
 * réel — `KlassciProxyService`, la stratégie de clés, le cache distribué (store
 * `database` en test) et le memo intra-requête. Le faux transport route sa
 * réponse d'après l'en-tête `Authorization: Bearer` reçu : un appelant ne peut
 * donc recevoir la charge utile d'un autre QUE si le cache la lui a servie sans
 * repasser par le réseau.
 *
 * @see .claude/specs/591-klassci-identity-scoped-cache/design.md
 */
final class ProxyAcademicPerUserIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_URL = 'https://klassci.tenant-591.test';

    /** Charge utile KLASSCI par porteur, pour chaque ressource liée à l'identité. */
    private const PAYLOADS = [
        'evaluations' => [
            'token-alice' => ['id' => 11, 'titre' => 'Devoir de maths — classe d\'Alice'],
            'token-bob'   => ['id' => 22, 'titre' => 'Devoir de SVT — classe de Bob'],
        ],
        'emploi-temps' => [
            'token-alice' => ['id' => 33, 'matiere' => 'Maths (Alice)'],
            'token-bob'   => ['id' => 44, 'matiere' => 'SVT (Bob)'],
        ],
    ];

    /** @var array<string, int> Nombre d'allers réseau observés, par ressource. */
    private array $networkCalls = [];

    /**
     * R1 — Deux enseignants du même tenant, jetons personnels distincts :
     * chacun DOIT recevoir ses propres évaluations.
     */
    public function test_two_users_of_same_tenant_do_not_share_evaluations(): void
    {
        [$alice, $bob] = $this->twoUsersWithPersonalTokens();
        $this->fakeKlassciTransportRoutingByBearer();

        $aliceResponse = $this->callAs($alice, '/api/proxy/evaluations');
        $aliceResponse->assertOk();
        self::assertSame(11, $aliceResponse->json('data.0.id'));

        $this->simulateFreshRequestBoundary();

        $bobResponse = $this->callAs($bob, '/api/proxy/evaluations');
        $bobResponse->assertOk();
        self::assertSame(
            22,
            $bobResponse->json('data.0.id'),
            'Bob a reçu les évaluations d\'Alice — fuite d\'identité via le cache '
            . 'KLASSCI à clé globale (#591).',
        );
    }

    /**
     * R2 — Même garantie sur l'emploi du temps.
     */
    public function test_two_users_of_same_tenant_do_not_share_emploi_temps(): void
    {
        [$alice, $bob] = $this->twoUsersWithPersonalTokens();
        $this->fakeKlassciTransportRoutingByBearer();

        $aliceResponse = $this->callAs($alice, '/api/proxy/emploi-temps');
        $aliceResponse->assertOk();
        self::assertSame(33, $aliceResponse->json('data.0.id'));

        $this->simulateFreshRequestBoundary();

        $bobResponse = $this->callAs($bob, '/api/proxy/emploi-temps');
        $bobResponse->assertOk();
        self::assertSame(
            44,
            $bobResponse->json('data.0.id'),
            'Bob a reçu l\'emploi du temps d\'Alice — fuite d\'identité via le '
            . 'cache KLASSCI à clé globale (#591).',
        );
    }

    /**
     * R3 — Fail-secure : un compte SANS jeton KLASSCI personnel n'a pas
     * d'identité KLASSCI ; il DOIT être refusé (401) et surtout ne JAMAIS
     * hériter de la charge utile d'un autre utilisateur restée en cache.
     *
     * Même contrat que `ProxyDashboardController` sur les endpoints personnels,
     * et que le fail-secure R2 de #568.
     */
    public function test_account_without_personal_token_is_refused_and_inherits_nothing(): void
    {
        $institution = $this->institution();
        $alice = $this->userWithPersonalToken($institution, 'token-alice');
        $tokenless = $this->userWithoutPersonalToken($institution);

        $this->fakeKlassciTransportRoutingByBearer();

        // Alice peuple d'abord le cache (ancien vecteur de la fuite).
        $this->callAs($alice, '/api/proxy/evaluations')->assertOk();

        $this->simulateFreshRequestBoundary();

        $response = $this->callAs($tokenless, '/api/proxy/evaluations');

        $response->assertStatus(401);
        self::assertStringNotContainsString(
            'Alice',
            (string) $response->getContent(),
            'Un compte sans jeton personnel ne doit hériter d\'aucune donnée d\'autrui.',
        );
        self::assertSame(
            1,
            $this->networkCalls['evaluations'] ?? 0,
            'Le refus doit être prononcé AVANT tout appel KLASSCI (fail-secure, pas de repli '
            . 'sur le jeton d\'institution).',
        );
    }

    /**
     * §1.3 — Multi-tenant : deux institutions distinctes. Le composant `{tenant}`
     * de la clé de cache n'est éprouvé par aucun des cas précédents (une seule
     * institution). On vérifie ici qu'un enseignant de l'institution B ne reçoit
     * jamais la charge utile mise en cache par l'institution A.
     */
    public function test_two_users_of_different_institutions_do_not_share_evaluations(): void
    {
        $alice = $this->userWithPersonalToken($this->institution(), 'token-alice');
        $bob   = $this->userWithPersonalToken($this->institution(), 'token-bob');
        self::assertNotSame($alice->institution_id, $bob->institution_id);

        $this->fakeKlassciTransportRoutingByBearer();

        $this->callAs($alice, '/api/proxy/evaluations')->assertOk();

        $this->simulateFreshRequestBoundary();

        $bobResponse = $this->callAs($bob, '/api/proxy/evaluations');
        $bobResponse->assertOk();
        self::assertSame(
            22,
            $bobResponse->json('data.0.id'),
            'Fuite CROSS-INSTITUTION : un enseignant de institution B a reçu les '
            . 'évaluations mises en cache par institution A.',
        );
        self::assertSame(2, $this->networkCalls['evaluations'] ?? 0);
    }

    /**
     * R5 — Garde anti-sur-correction : les endpoints réellement tenant-partagés
     * (catalogue organisationnel) DOIVENT conserver leur clé globale. Deux
     * porteurs distincts ne provoquent qu'un seul aller réseau.
     */
    public function test_tenant_wide_catalogue_endpoints_keep_a_shared_cache_entry(): void
    {
        [$alice, $bob] = $this->twoUsersWithPersonalTokens();
        $this->fakeKlassciTransportRoutingByBearer();

        $this->callAs($alice, '/api/proxy/structure')->assertOk();
        $this->simulateFreshRequestBoundary();
        $this->callAs($bob, '/api/proxy/structure')->assertOk();

        self::assertSame(
            1,
            $this->networkCalls['structure'] ?? 0,
            'La structure organisationnelle est identique pour tout le tenant : '
            . 'la partitionner par porteur détruirait le taux de hit sans gain '
            . 'de sécurité (R5).',
        );
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function institution(): Institution
    {
        return Institution::factory()->create([
            'klassci_api_url'   => self::TENANT_URL,
            'klassci_api_token' => 'token-institution',
            'is_active'         => true,
        ]);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function twoUsersWithPersonalTokens(): array
    {
        $institution = $this->institution();

        return [
            $this->userWithPersonalToken($institution, 'token-alice'),
            $this->userWithPersonalToken($institution, 'token-bob'),
        ];
    }

    private function userWithPersonalToken(Institution $institution, string $token): User
    {
        return User::factory()->for($institution)->create([
            'role'               => 'enseignant',
            'klassci_token'      => $token,
            'klassci_tenant_url' => self::TENANT_URL,
            // Données fraîches → EnsureKlassciSync ne déclenche aucun appel
            // KLASSCI : on isole le comportement du contrôleur proxy.
            'last_klassci_sync'  => now(),
        ]);
    }

    private function userWithoutPersonalToken(Institution $institution): User
    {
        return User::factory()->for($institution)->create([
            'role'               => 'enseignant',
            'klassci_token'      => null,
            'klassci_tenant_url' => self::TENANT_URL,
            'last_klassci_sync'  => now(),
        ]);
    }

    // ------------------------------------------------------------------
    // Harnais
    // ------------------------------------------------------------------

    private function callAs(User $user, string $uri): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->getJson($uri);
    }

    /**
     * Feinte de la SEULE frontière réseau. La réponse dépend du porteur reçu :
     * si un appelant obtient la charge utile d'un autre, c'est nécessairement le
     * cache qui la lui a servie.
     */
    private function fakeKlassciTransportRoutingByBearer(): void
    {
        $factory = new HttpFactory();
        $factory->fake(function (ClientRequest $request) {
            $resource = $this->resourceFromUrl($request->url());
            $this->networkCalls[$resource] = ($this->networkCalls[$resource] ?? 0) + 1;

            $bearer = $request->header('Authorization')[0] ?? '';
            $token  = str_replace('Bearer ', '', $bearer);

            $payload = self::PAYLOADS[$resource][$token] ?? ['id' => 0, 'bearer' => $token];

            return Http::response(['success' => true, 'data' => [$payload]]);
        });

        $this->app->instance(HttpFactory::class, $factory);
    }

    /** Dernier segment de chemin de l'URL appelée, hors query string. */
    private function resourceFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return basename($path);
    }

    /**
     * Simule la frontière entre deux requêtes HTTP distinctes.
     *
     * Deux états doivent être purgés pour que la simulation soit fidèle à la
     * production (un process = une requête sous PHP-FPM) :
     *
     *  1. `forgetScopedInstances()` — memo intra-requête, tenant, résolveur de
     *     config KLASSCI.
     *  2. `Route::flushController()` — Laravel **mémoïse l'instance de
     *     contrôleur sur l'objet `Route`**, qui survit à la requête dans le
     *     RouteCollection ; seul Octane appelle ce flush. Sans lui, le
     *     contrôleur de la 1ʳᵉ requête (et TOUTES ses dépendances résolues au
     *     constructeur) resservirait à la 2ᵈᵉ, ce qui ne correspond ni au
     *     comportement PHP-FPM ni au comportement Octane.
     *
     * Le faux transport (binding `instance`) et le cache distribué survivent —
     * ce dernier est précisément le vecteur qu'on cherche à éprouver.
     */
    private function simulateFreshRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();

        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }
    }
}
