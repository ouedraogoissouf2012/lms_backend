<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Exceptions\KlassciUnavailableException;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Le front de production est cross-origin (lms.klassci.com → apilms.klassci.com).
 * Le navigateur n'y laisse lire à JavaScript que les en-têtes CORS-safelisted et
 * ceux que `Access-Control-Expose-Headers` nomme. `Retry-After` et `X-RateLimit-*`
 * n'en font pas partie : avec `exposed_headers: []`, le délai d'un 503 KLASSCI ou
 * d'un 429 restait invisible au front (frontend_lms#426), alors qu'il est lisible
 * en dev, où le proxy Vite sert tout depuis la même origine.
 *
 * L'en-tête sert à lire une VALEUR (le délai, le quota restant). La CLASSE d'une
 * erreur ne dépend pas de lui : le 503 canonique porte `reason` dans son corps
 * (ADR-906-01 : un motif va dans le corps, pas dans un en-tête).
 *
 * Ce qui est réel : la route, le jeton porteur (`asTenant`), l'en-tête `Origin` et
 * le middleware CORS. KLASSCI est simulé. L'origine admise est celle par défaut
 * (`edu.klassci.com`) : une seule origine admise, comme en production.
 */
final class CorsExposedHeadersTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const ALLOWED_ORIGIN = 'https://edu.klassci.com';

    private const UNTRUSTED_ORIGIN = 'https://attaquant.example';

    private const EXPOSED = ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'];

    public function test_a_klassci_outage_exposes_its_delay_and_carries_its_reason(): void
    {
        $response = $this->klassciOutageFrom(self::ALLOWED_ORIGIN);

        $response->assertStatus(503)
            ->assertHeader('Access-Control-Allow-Origin', self::ALLOWED_ORIGIN)
            ->assertHeader('Retry-After', (string) KlassciUnavailableException::retryAfterSeconds())
            ->assertExactJson([
                'success' => false,
                'message' => KlassciUnavailableException::CLIENT_MESSAGE,
                'reason' => 'klassci_unavailable',
            ]);
        self::assertSame(self::EXPOSED, $this->exposedHeaders($response));
    }

    public function test_a_throttled_request_exposes_its_delay_and_its_quota(): void
    {
        $user = User::factory()->create([
            'institution_id' => Institution::factory()->create()->id,
            'role' => 'etudiant',
        ]);
        $this->asTenant($user)->withHeaders(['Origin' => self::ALLOWED_ORIGIN]);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/search?query=math')->assertStatus(200);
        }
        $response = $this->getJson('/api/search?query=math');

        $response->assertStatus(429)
            ->assertHeader('Access-Control-Allow-Origin', self::ALLOWED_ORIGIN)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '30')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('X-RateLimit-Reset');
        self::assertSame(self::EXPOSED, $this->exposedHeaders($response));
    }

    /**
     * L'exposition ne vaut que pour une origine admise. Avec une seule origine
     * admise, cas de la production, le middleware renvoie cette origine à toute
     * requête, et donc aussi la liste `Access-Control-Expose-Headers`. Ce n'est
     * pas elle qui protège, c'est `Access-Control-Allow-Origin` : tant qu'il ne
     * nomme ni l'origine tierce ni le joker `*`, le navigateur ne laisse lire à
     * celle-ci aucune partie de la réponse, en-têtes exposés compris.
     */
    public function test_an_untrusted_origin_reads_nothing_of_the_response(): void
    {
        $response = $this->klassciOutageFrom(self::UNTRUSTED_ORIGIN);

        $response->assertStatus(503);
        $allowOrigin = $response->headers->get('Access-Control-Allow-Origin');
        if ($allowOrigin !== null) {
            self::assertNotSame('*', $allowOrigin);
            self::assertContains($allowOrigin, config('cors.allowed_origins'));
            self::assertNotSame(self::UNTRUSTED_ORIGIN, $allowOrigin);
        }
    }

    /**
     * @return TestResponse<Response>
     */
    private function klassciOutageFrom(string $origin): TestResponse
    {
        $this->disableKlassciMiddleware();
        $this->mock(KlassciProxyService::class, function (MockInterface $m): void {
            $m->shouldReceive('getEnseignantsEnrichis')
                ->once()
                ->andThrow(KlassciUnavailableException::upstreamFailure(502));
        });
        $admin = User::factory()->create([
            'institution_id' => Institution::factory()->create()->id,
            'role' => 'admin',
            'klassci_token' => 'jeton-valide',
        ]);

        return $this->asTenant($admin)
            ->withHeaders(['Origin' => $origin])
            ->getJson('/api/lms/enseignants');
    }

    /**
     * @return list<string>
     */
    private function exposedHeaders(TestResponse $response): array
    {
        $value = (string) $response->headers->get('Access-Control-Expose-Headers', '');

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
