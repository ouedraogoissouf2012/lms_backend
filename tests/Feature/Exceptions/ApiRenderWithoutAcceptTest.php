<?php

declare(strict_types=1);

namespace Tests\Feature\Exceptions;

use App\Http\Presenters\TooManyRequestsPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Une route `api/*` rend son erreur en JSON, que la requête annonce
 * `Accept: application/json` ou non : la règle de #244, appliquée au 401, au
 * 422 et au 503, vaut aussi pour le 429 et pour le rendu générique (403, 404,
 * 405, 500). Voir aussi #388.
 *
 * Le 429 garde ses en-têtes `Retry-After` et `X-RateLimit-*` (#214). Une route
 * web garde le rendu de Laravel.
 *
 * @see bootstrap/app.php withExceptions()
 */
final class ApiRenderWithoutAcceptTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE_GENERIQUE = 'Une erreur est survenue.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('app.debug', false);

        Route::middleware('api')->prefix('api/_rendus')->group(function (): void {
            Route::get('lecture', fn () => ['ok' => true]);
            Route::get('refus', fn () => abort(403));
            Route::get('panne', fn () => throw new RuntimeException('Panne de diagnostic.'));
            Route::get('debit', fn () => ['ok' => true])->middleware('throttle:1,1');
            Route::get('indisponible', fn () => abort(503, 'Maintenance', ['Retry-After' => '120']));
        });
    }

    /** @return iterable<string, array{0: string, 1: string, 2: int}> */
    public static function erreursGeneriques(): iterable
    {
        yield '403' => ['get', '/api/_rendus/refus', 403];
        yield '404, route absente' => ['get', '/api/_rendus/absente', 404];
        yield '405, méthode refusée' => ['post', '/api/_rendus/lecture', 405];
        yield '500' => ['get', '/api/_rendus/panne', 500];
    }

    #[DataProvider('erreursGeneriques')]
    public function test_une_erreur_d_une_route_api_sans_accept_json_est_rendue_en_json(string $methode, string $uri, int $statut): void
    {
        $this->assertJsonGenerique($this->call(strtoupper($methode), $uri), $statut);
    }

    #[DataProvider('erreursGeneriques')]
    public function test_la_meme_erreur_avec_accept_json_est_inchangee(string $methode, string $uri, int $statut): void
    {
        $this->assertJsonGenerique($this->json(strtoupper($methode), $uri), $statut);
    }

    public function test_le_429_d_une_route_api_sans_accept_json_garde_ses_en_tetes(): void
    {
        $this->get('/api/_rendus/debit')->assertOk();

        $reponse = $this->get('/api/_rendus/debit')
            ->assertStatus(429)
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['success' => false, 'message' => TooManyRequestsPresenter::MESSAGE])
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '1')
            ->assertHeader('X-RateLimit-Remaining', '0');

        self::assertGreaterThan(0, (int) $reponse->headers->get('Retry-After'));
    }

    /**
     * Les en-têtes d'une `HttpException` sont gardés : `Allow` d'un 405 (RFC 9110
     * §15.5.6), `Retry-After` d'un 503 (mode maintenance, `abort`).
     *
     * @return iterable<string, array{0: bool}>
     */
    public static function accepts(): iterable
    {
        yield 'sans Accept JSON' => [false];
        yield 'avec Accept JSON' => [true];
    }

    #[DataProvider('accepts')]
    public function test_les_en_tetes_d_une_http_exception_sont_gardes(bool $json): void
    {
        $refus = $json ? $this->postJson('/api/_rendus/lecture') : $this->post('/api/_rendus/lecture');
        $refus->assertStatus(405)->assertHeader('Content-Type', 'application/json');
        self::assertStringContainsString('GET', (string) $refus->headers->get('Allow'));

        $indisponible = $json ? $this->getJson('/api/_rendus/indisponible') : $this->get('/api/_rendus/indisponible');
        $indisponible->assertStatus(503)->assertHeader('Content-Type', 'application/json')->assertHeader('Retry-After', '120');
    }

    public function test_une_route_web_absente_garde_le_rendu_de_laravel(): void
    {
        $reponse = $this->get('/route-web-absente')->assertNotFound();

        self::assertStringStartsWith('text/html', (string) $reponse->headers->get('Content-Type'));
    }

    private function assertJsonGenerique(TestResponse $reponse, int $statut): void
    {
        $reponse->assertStatus($statut)
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['success' => false, 'message' => self::MESSAGE_GENERIQUE]);
    }
}
