<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\KlassciConfigResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #75 — La résolution Priority 2 du `KlassciConfigResolver` doit utiliser
 * la relation directe user→institution, JAMAIS un lookup-by-URL.
 *
 * ## Historique
 *
 * Bug historique : avant le fix #75, la priorité 2 cherchait l'institution avec
 *     Institution::where('klassci_api_url', $url)->first()
 * et cachait le résultat sous `'institution_by_url_' . md5($url)`. Si deux
 * institutions partagent le même serveur KLASSCI (même `klassci_api_url`),
 * la query renvoyait UNE des deux (non-déterministe), et le cache figeait le
 * choix → toutes les requêtes proxy de l'autre école étaient authentifiées
 * avec le token de la première (cross-institution leak).
 *
 * Fix : utiliser `$user->institution` (FK institution_id), avec check défensif
 * que `klassci_api_url` matche. Si désaccord → fail-secure vers Priority 3.
 *
 * ## PERF-02 (issue #137) — Refactor post-split
 *
 * Avant le split architectural de PR 1 PERF-02, la méthode `resolveConfig()` et
 * les props `$token`/`$baseUrl` vivaient en privé sur `KlassciProxyService`. Le
 * test devait passer par Reflection. Depuis le split, la résolution vit dans
 * `KlassciConfigResolver` avec accesseurs **publics** `baseUrl()` + `token()` —
 * la Reflection est éliminée, le contract anti-régression #75 est testé direct.
 *
 * @see \App\Services\Klassci\KlassciConfigResolver
 * @see Issue #75 (cross-institution token leak)
 * @see Issue #23 (analog précédent dans AdminAnalyticsController)
 * @see OWASP A01:2021 Broken Access Control
 */
final class KlassciProxyServiceInstitutionResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_URL = 'https://klassci-shared.example.com';

    private Institution $institutionA;

    private Institution $institutionB;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {

        parent::setUp();

        // Deux institutions DIFFÉRENTES partageant le même serveur KLASSCI.
        // C'est précisément la condition qui déclenchait la fuite #75.
        $this->institutionA = Institution::factory()->create([
            'slug'                        => 'school-a',
            'klassci_api_url'             => self::SHARED_URL,
            'klassci_api_token_encrypted' => 'plain-token-A',
            'is_active'                   => true,
        ]);
        $this->institutionB = Institution::factory()->create([
            'slug'                        => 'school-b',
            'klassci_api_url'             => self::SHARED_URL,
            'klassci_api_token_encrypted' => 'plain-token-B',
            'is_active'                   => true,
        ]);

        // Users sans token personnel → on déclenche Priority 2 du resolver.
        $this->userA = User::factory()->create([
            'institution_id'          => $this->institutionA->id,
            'klassci_tenant_url'      => self::SHARED_URL,
            'klassci_token_encrypted' => null,
        ]);
        $this->userB = User::factory()->create([
            'institution_id'          => $this->institutionB->id,
            'klassci_tenant_url'      => self::SHARED_URL,
            'klassci_token_encrypted' => null,
        ]);
    }

    /**
     * Resolve un KlassciConfigResolver FRAIS (le resolver est lazy + memoized
     * sur sa propre instance, donc on doit en créer une nouvelle à chaque test
     * pour éviter le réutilisé du container Laravel singleton).
     */
    private function freshResolver(): KlassciConfigResolver
    {
        // Forge une nouvelle instance via le container (pour bénéficier de l'auto-wire
        // des dépendances : AuthFactory, TenantManager, LoggerInterface).
        $this->app->forgetInstance(KlassciConfigResolver::class);

        return app(KlassciConfigResolver::class);
    }

    /**
     * Happy path : utilisateur résolu → token de SA propre institution.
     */
    public function test_user_resolves_to_their_own_institution_token(): void
    {
        Sanctum::actingAs($this->userA);

        $resolver = $this->freshResolver();

        self::assertSame('plain-token-A', $resolver->token());
        self::assertSame(self::SHARED_URL, $resolver->baseUrl());
    }

    /**
     * Assertion centrale de l'issue #75 : deux users d'institutions différentes
     * partageant la même klassci_api_url doivent recevoir leurs tokens distincts.
     * Avec l'ancien cache md5(url) → tokenA aurait été servi à userB.
     */
    public function test_two_users_sharing_klassci_url_get_distinct_tokens(): void
    {
        // Resolve A
        Sanctum::actingAs($this->userA);
        $resolverA = $this->freshResolver();
        $tokenA = $resolverA->token();

        // Resolve B (aurait été pollué par le cache md5(url) avant fix)
        Sanctum::actingAs($this->userB);
        $resolverB = $this->freshResolver();
        $tokenB = $resolverB->token();

        self::assertSame('plain-token-A', $tokenA, 'User A must get institution A token.');
        self::assertSame('plain-token-B', $tokenB, 'User B must get institution B token.');
        self::assertNotSame(
            $tokenA,
            $tokenB,
            'Cross-institution token leak detected — issue #75 regression.'
        );
    }

    /**
     * Défense en profondeur : si l'URL klassci_tenant_url du user ne matche pas
     * celle de son institution (incohérence de données), Priority 2 doit échouer
     * et le code doit retomber sur Priority 3 (config système). Surtout pas
     * monter le token d'une autre institution juste parce que son URL correspond.
     */
    public function test_fails_priority_2_when_user_url_does_not_match_institution(): void
    {
        $this->userA->klassci_tenant_url = 'https://different-server.example.com';
        $this->userA->save();

        Sanctum::actingAs($this->userA);

        $resolver = $this->freshResolver();
        $token = $resolver->token();

        // Surtout pas le token de l'institution A (URL ne matche pas) et surtout
        // pas le token de B juste parce que B a la SHARED_URL.
        self::assertNotSame('plain-token-A', $token);
        self::assertNotSame('plain-token-B', $token);
    }

    /**
     * Institution désactivée → Priority 2 doit sauter, jamais monter son token.
     */
    public function test_fails_priority_2_when_institution_is_inactive(): void
    {
        $this->institutionA->is_active = false;
        $this->institutionA->save();

        Sanctum::actingAs($this->userA);

        $resolver = $this->freshResolver();

        self::assertNotSame(
            'plain-token-A',
            $resolver->token(),
            'Priority 2 ne doit jamais utiliser le token d\'une institution désactivée.'
        );
    }

    /**
     * User sans klassci_tenant_url → Priority 2 sautée (gardée par le `if`).
     */
    public function test_fails_priority_2_when_user_tenant_url_is_null(): void
    {
        $this->userA->klassci_tenant_url = null;
        $this->userA->save();

        Sanctum::actingAs($this->userA);

        $resolver = $this->freshResolver();

        self::assertNotSame('plain-token-A', $resolver->token());
    }

    /**
     * Institution avec token vide → Priority 2 sautée, fail-secure vers Priority 3.
     */
    public function test_fails_priority_2_when_institution_token_is_empty(): void
    {
        $this->institutionA->klassci_api_token = null;
        $this->institutionA->save();

        Sanctum::actingAs($this->userA);

        $resolver = $this->freshResolver();

        self::assertNotSame(
            '',
            $resolver->token(),
            'Le resolver ne doit JAMAIS monter un token vide.'
        );
    }
}
