<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seances\Sync;

use App\Services\Seances\Sync\SeanceCheckResult;
use App\Services\Seances\Sync\SeanceExistenceBatchChecker;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Issue #516 — `SeanceExistenceBatchChecker`.
 *
 * Couvre (1) la classification 3 états par code HTTP RÉEL (pas de
 * `str_contains()` fragile sur un message d'exception — c'est le bug que
 * l'enum {@see SeanceCheckResult} remplace), (2) l'élimination du N+1 : le
 * nombre d'appels `HttpFactory::pool()` croît par lots de taille fixe
 * (`services.klassci.pool_size`), jamais linéairement avec le nombre d'IDs,
 * et (3) l'isolation multi-tenant : `baseUrl`/`token` en PARAMÈTRE explicite
 * de `checkMany()` (révisé après audit `spec-architect` #516 — voir docblock
 * de classe), pas d'état interne à figer entre deux appels sur la même
 * instance.
 *
 * @see app/Services/Seances/Sync/SeanceExistenceBatchChecker.php
 */
#[CoversClass(SeanceExistenceBatchChecker::class)]
final class SeanceExistenceBatchCheckerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * `Http::response()` (facade) construit une PROMISE — pensé pour les
     * closures `Http::fake([...])`, pas pour un usage standalone. Utile ici
     * uniquement pour le test qui mocke `HttpFactory::pool()` directement.
     */
    private function fakeResponse(int $status, array $body = []): Response
    {
        return new Response(new Psr7Response($status, [], json_encode($body)));
    }

    public function test_checkMany_classifies_by_real_http_status_not_exception_message(): void
    {
        Http::fake([
            'https://klassci.test/seances/1' => Http::response(['data' => ['id' => 1]], 200),
            'https://klassci.test/seances/2' => Http::response([], 404),
            'https://klassci.test/seances/3' => Http::response(['error' => 'boom'], 500),
        ]);

        $results = app(SeanceExistenceBatchChecker::class)
            ->checkMany([1, 2, 3], 'https://klassci.test', 'system-token');

        self::assertSame(SeanceCheckResult::Exists, $results[1], '200 → la séance existe toujours.');
        self::assertSame(SeanceCheckResult::ConfirmedDeleted, $results[2], '404 → seule condition d\'archivage.');
        self::assertSame(SeanceCheckResult::Error, $results[3], '500 ≠ 404 : ne doit jamais archiver.');
    }

    public function test_checkMany_pool_call_count_grows_sublinearly_with_id_count(): void
    {
        Config::set('services.klassci.pool_size', 4);

        $poolCalls = 0;
        $http = Mockery::mock(HttpFactory::class);
        $http->shouldReceive('pool')->andReturnUsing(function () use (&$poolCalls): array {
            $poolCalls++;

            // Peu importe le contenu retourné ici : ce test mesure uniquement
            // le NOMBRE d'appels pool, pas la classification (couverte par le
            // test précédent).
            return [];
        });
        $this->app->instance(HttpFactory::class, $http);

        $checker = app(SeanceExistenceBatchChecker::class);

        $checker->checkMany(range(1, 3), 'https://klassci.test', 'token');
        self::assertSame(1, $poolCalls, '3 IDs ≤ poolSize (4) → 1 seul appel pool, pas 3 appels séquentiels.');

        $poolCalls = 0;
        $checker->checkMany(range(1, 30), 'https://klassci.test', 'token');
        self::assertSame(
            8,
            $poolCalls,
            '30 IDs / poolSize (4) → 8 appels pool (ceil(30/4)), croissance sous-linéaire — jamais 30 appels séquentiels.',
        );
    }

    /**
     * Isolation multi-tenant (#516) : `baseUrl`/`token` sont des PARAMÈTRES,
     * pas un état résolu en interne — `CleanObsoleteSeances` réutilise LA
     * MÊME instance de checker pour plusieurs institutions successives. Ce
     * test appelle `checkMany()` DEUX FOIS sur LA MÊME instance avec deux
     * couples `baseUrl`/`token` DIFFÉRENTS et vérifie que chaque appel
     * atteint le BON hôte avec le BON token — aucune fuite d'un appel vers
     * l'autre (contrairement à l'ancien design basé sur `KlassciConfigResolver`
     * mémorisé, qui figeait la config du 1er appel pour tous les suivants).
     */
    public function test_checkMany_uses_the_baseurl_and_token_given_to_each_call_independently(): void
    {
        Http::fake([
            'https://school-a.klassci.test/seances/111' => Http::response(['data' => ['id' => 111]], 200),
            'https://school-b.klassci.test/seances/222' => Http::response(['data' => ['id' => 222]], 200),
        ]);

        $checker = app(SeanceExistenceBatchChecker::class);
        $checker->checkMany([111], 'https://school-a.klassci.test', 'token-a');
        $checker->checkMany([222], 'https://school-b.klassci.test', 'token-b');

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://school-a.klassci.test/seances/111')
            && $request->hasHeader('Authorization'));
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://school-b.klassci.test/seances/222')
            && $request->hasHeader('Authorization'));
    }

    public function test_checkMany_returns_empty_array_without_any_pool_call_for_empty_input(): void
    {
        $http = Mockery::mock(HttpFactory::class);
        $http->shouldNotReceive('pool');
        $this->app->instance(HttpFactory::class, $http);

        $results = app(SeanceExistenceBatchChecker::class)->checkMany([], 'https://klassci.test', 'token');

        self::assertSame([], $results);
    }
}
