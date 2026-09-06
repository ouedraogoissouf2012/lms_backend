<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Klassci;

use App\Services\Klassci\KlassciBatchResponseCollector;
use App\Services\Klassci\KlassciCircuitBreaker;
use App\Services\Klassci\KlassciRequestMemo;
use App\Services\Klassci\KlassciResilientPool;
use App\Services\Klassci\KlassciTargetResolver;
use App\Services\Klassci\KlassciTransportRetry;
use App\Services\Klassci\PoolTarget;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * L'EXÉCUTION de la boucle de réessai du pool (#744).
 *
 * ## Le trou que ce fichier comble
 *
 * `KlassciBatchResponseCollectorTest` verrouille le TRI — quel échec est
 * rejouable — mais rien ne vérifiait que la boucle rejoue vraiment. Aucun test
 * du dépôt n'injectait un non-`Response` dans le pool : tous passaient par
 * `Http::fake()` avec de vraies réponses, si bien que `collect()` rendait
 * toujours `[]` et que la boucle sortait au premier tour.
 *
 * Conséquence mesurée : ramener le nombre de tentatives à 1 restaurait
 * exactement le défaut #744 — un SYN avalé, un effectif de classe à 0 — **sans
 * qu'aucun test ne rougisse**. Découvert par une revue adversariale, pas par
 * ma propre falsification, qui n'avait porté que sur le tri.
 *
 * La technique de feinte reprend celle déjà présente au dépôt
 * (`KlassciTenantDiscoveryTest` : mocker `Factory::pool` et rendre un tableau
 * mêlant `ConnectionException` et `Response`).
 */
#[CoversClass(KlassciResilientPool::class)]
final class KlassciResilientPoolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
    }

    protected function tearDown(): void
    {
        Cache::store('array')->flush();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * LE comportement que rien ne verrouillait : un échec de transport doit
     * déclencher un SECOND pool, ne portant que l'identifiant tombé.
     */
    public function test_a_transport_failure_triggers_a_second_pool_with_only_the_failed_id(): void
    {
        $passes = [];
        $http = $this->httpReturning([
            [1 => new ConnectionException('cURL error 28'), 2 => $this->ok(['data' => 'deux'])],
            [1 => $this->ok(['data' => 'un'])],
        ], $passes);

        $resolved = [];
        $this->pool($http)->run([$this->batch([1, 2])], $this->target(), 600, $resolved);

        self::assertCount(2, $passes, 'Un échec de transport doit provoquer un second pool.');
        self::assertSame([1], $passes[1], 'Le second pool ne doit porter QUE l\'identifiant tombé.');
        self::assertSame(['data' => 'un'], $resolved[1]);
        self::assertSame(['data' => 'deux'], $resolved[2]);
    }

    /**
     * Le budget est borné : trois tentatives, pas davantage. Marteler l'hôte
     * qui filtre sur le volume aggraverait la cause.
     */
    public function test_the_number_of_pools_is_capped_at_three(): void
    {
        $passes = [];
        $http = $this->httpReturning(array_fill(0, 6, [1 => new ConnectionException('cURL error 28')]), $passes);

        $resolved = [];
        $this->pool($http)->run([$this->batch([1])], $this->target(), 600, $resolved);

        self::assertCount(3, $passes);
        self::assertSame([], $resolved);
    }

    /**
     * Une réponse HTTP d'erreur ne déclenche JAMAIS de second pool : KLASSCI a
     * répondu.
     */
    public function test_an_http_error_never_triggers_a_second_pool(): void
    {
        $passes = [];
        $http = $this->httpReturning([[1 => $this->ok([], 404)]], $passes);

        $resolved = [];
        $this->pool($http)->run([$this->batch([1])], $this->target(), 600, $resolved);

        self::assertCount(1, $passes);
    }

    /**
     * LA borne qui protège le chemin synchrone : disjoncteur déjà ouvert, on
     * n'ouvre aucune connexion.
     *
     * Sans elle, une cible injoignable coûterait 3 tentatives PAR LOT — sur 10
     * lots à 2 s de délai de connexion, plus de 60 s, soit un 504 au lieu d'une
     * page dégradée mais servie.
     */
    public function test_an_open_circuit_stops_before_any_call(): void
    {
        $passes = [];
        $http = $this->httpReturning([[1 => $this->ok(['data' => 'x'])]], $passes);

        $breaker = $this->breaker();
        $breaker->reportFailure();
        $breaker->reportFailure();
        $breaker->reportFailure();
        self::assertTrue($breaker->isOpen(), 'Pré-condition : le disjoncteur doit être ouvert.');

        $resolved = [];
        $this->pool($http, $breaker)->run([$this->batch([1])], $this->target(), 600, $resolved);

        self::assertSame([], $passes, 'Disjoncteur ouvert : aucune connexion ne doit être ouverte.');
    }

    /**
     * Un lot épuisé ALIMENTE le disjoncteur — sinon les échecs du pool
     * resteraient invisibles, et il continuerait de marteler l'hôte pendant que
     * le chemin mono-requête, lui, se met en retrait.
     */
    public function test_an_exhausted_batch_feeds_the_circuit_breaker(): void
    {
        $passes = [];
        $http = $this->httpReturning(array_fill(0, 5, [1 => new ConnectionException('cURL error 28')]), $passes);

        $breaker = $this->breaker();
        $resolved = [];

        // Trois lots épuisés = le seuil par défaut du disjoncteur.
        for ($i = 0; $i < 3; $i++) {
            $this->pool($http, $breaker)->run([$this->batch([1])], $this->target(), 600, $resolved);
        }

        self::assertTrue($breaker->isOpen(), 'Les échecs du pool doivent compter pour le disjoncteur.');
    }

    // ───────────────────── Fixtures ─────────────────────

    /**
     * @param  list<int>  $ids
     * @return array<int, array{endpoint: string, memoKey: string, cacheKey: string}>
     */
    private function batch(array $ids): array
    {
        $batch = [];
        foreach ($ids as $id) {
            $batch[$id] = [
                'endpoint' => "classes/{$id}",
                'memoKey' => "memo:{$id}",
                'cacheKey' => "cache:{$id}",
            ];
        }

        return $batch;
    }

    /**
     * Feinte de `Factory::pool` rendant une suite de résultats, et consignant
     * les identifiants demandés à chaque passe.
     *
     * @param  list<array<int, mixed>>  $passesPrevues
     * @param  list<list<int>>  $passesObservees
     */
    private function httpReturning(array $passesPrevues, array &$passesObservees): HttpFactory
    {
        $passesObservees = [];
        $rang = 0;

        /** @var HttpFactory&Mockery\MockInterface $http */
        $http = Mockery::mock(HttpFactory::class);
        $http->shouldReceive('pool')->andReturnUsing(
            function (\Closure $callback) use (&$rang, &$passesObservees, $passesPrevues): array {
                // Le callback construit les requêtes : on l'exécute avec un
                // collecteur d'identifiants au lieu d'un vrai Pool.
                $espion = new PoolIdSpy;
                $callback($espion);
                $passesObservees[] = $espion->ids;

                $prevu = $passesPrevues[$rang++] ?? [];
                $parCle = [];
                foreach ($prevu as $id => $valeur) {
                    $parCle[(string) $id] = $valeur;
                }

                return $parCle;
            }
        );

        return $http;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ok(array $payload, int $status = 200): \Illuminate\Http\Client\Response
    {
        return new \Illuminate\Http\Client\Response(
            new Response($status, [], (string) json_encode($payload))
        );
    }

    private function target(): PoolTarget
    {
        return new PoolTarget('https://klassci.test/api/lms', 'jeton', 2, 5, true);
    }

    private function breaker(): KlassciCircuitBreaker
    {
        $cible = Mockery::mock(KlassciTargetResolver::class);
        $cible->shouldReceive('baseUrl')->andReturn('https://klassci.test/api/lms');

        return new KlassciCircuitBreaker(Cache::store('array'), $cible, new NullLogger);
    }

    private function pool(HttpFactory $http, ?KlassciCircuitBreaker $breaker = null): KlassciResilientPool
    {
        return new KlassciResilientPool(
            $http,
            new KlassciBatchResponseCollector(Cache::store('array'), new KlassciRequestMemo, new NullLogger),
            new KlassciTransportRetry,
            $breaker ?? $this->breaker(),
        );
    }
}

/**
 * Faux `Pool` : retient les identifiants demandés sans ouvrir de connexion.
 *
 * `as()` rend un double de `PendingRequest` — le type qu'exige
 * `KlassciHttpClient::decorateRequest()` — dont toutes les méthodes de
 * construction se renvoient elles-mêmes. Aucune requête n'est jamais émise.
 */
final class PoolIdSpy
{
    /** @var list<int> */
    public array $ids = [];

    public function as(string $key): PendingRequest
    {
        $this->ids[] = (int) $key;

        /** @var PendingRequest&Mockery\MockInterface $req */
        $req = Mockery::mock(PendingRequest::class);
        foreach (['connectTimeout', 'timeout', 'withHeaders', 'withToken', 'withoutVerifying', 'get'] as $methode) {
            $req->shouldReceive($methode)->andReturnSelf();
        }

        return $req;
    }
}
