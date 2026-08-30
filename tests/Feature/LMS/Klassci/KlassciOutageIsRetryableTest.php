<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Klassci;

use App\Exceptions\KlassciUnavailableException;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Une panne KLASSCI doit rester RETRYABLE, jamais devenir une erreur définitive.
 *
 * ## Le défaut
 *
 * `LMSEnseignantsController` et `LMSMatieresAdminController` enveloppent leur
 * appel KLASSCI dans un `catch (\Exception)` et répondent 500. Or
 * {@see KlassciUnavailableException} — levée par `KlassciHttpClient` sur tout 5xx,
 * sur circuit ouvert, et sur URL de base absente — étend `Exception` : elle est
 * donc avalée, et le 503 canonique (avec `Retry-After`) n'est jamais émis.
 *
 * Un 500 dit au client « le serveur a un bug, ne réessaie pas ». Un 503 dit
 * « indisponibilité temporaire, réessaie dans N secondes ». Confondre les deux
 * transforme une coupure de quelques minutes en échec définitif côté interface.
 *
 * Le handler global de `bootstrap/app.php` est documenté comme « canonique :
 * couvre tout appelant qui laisse remonter l'exception ». Ces deux contrôleurs
 * l'interceptaient avant lui.
 *
 * ## Découvert via le compte plateforme
 *
 * Un `supradmin` (sans institution) n'a aucune URL KLASSCI résoluble : le
 * résolveur lève `KlassciUnavailableException`, et `/api/lms/enseignants`
 * répondait 500. Le défaut n'est pourtant PAS propre à ce compte — il frappe
 * TOUT utilisateur dès que KLASSCI est réellement en panne.
 *
 * ## Périmètre volontairement étroit
 *
 * Seul le cas panne change. L'enveloppe 500 générique de chaque contrôleur
 * (clés `data` / `error` hors contrat standard) reste identique au caractère
 * près — elle est vérifiée ici par un test dédié.
 *
 * @see app/Exceptions/KlassciUnavailableException.php::jsonResponse()
 */
final class KlassciOutageIsRetryableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * endpoint => méthode du proxy RÉELLEMENT appelée par le contrôleur.
     *
     * `LMSMatieresAdminController` passe par `requestWithUserToken($token,
     * 'matieres', 'GET')`, pas par le raccourci `getMatieres()` : feindre le
     * raccourci laisserait la vraie méthode s'exécuter et le test mesurerait
     * autre chose que ce qu'il prétend.
     */
    private const ENDPOINTS = [
        'enseignants' => ['/api/lms/enseignants', 'getEnseignantsEnrichis'],
        'admin-matieres' => ['/api/admin/matieres', 'requestWithUserToken'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        Sanctum::actingAs(User::factory()->create([
            'institution_id' => Institution::factory()->create()->id,
            'role' => 'admin',
            'klassci_token' => 'jeton-valide',
        ]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function endpointProvider(): array
    {
        return ['enseignants' => ['enseignants'], 'admin-matieres' => ['admin-matieres']];
    }

    private function klassciFails(string $method, \Throwable $e): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $m) use ($method, $e): void {
            $m->shouldReceive($method)->andThrow($e);
            $m->shouldReceive('getMatiereDetails')->andThrow($e);
        });
    }

    #[DataProvider('endpointProvider')]
    public function test_klassci_outage_returns_503_with_retry_after(string $key): void
    {
        [$url, $method] = self::ENDPOINTS[$key];
        $this->klassciFails($method, KlassciUnavailableException::upstreamFailure(502));

        $response = $this->getJson($url);

        $response->assertStatus(503);
        $response->assertHeader('Retry-After');
        $response->assertJsonPath('message', KlassciUnavailableException::CLIENT_MESSAGE);
    }

    /**
     * Le cas #270 : URL de base absente ou invalide. C'est cette variante que
     * rencontre un compte plateforme sans institution résoluble.
     */
    #[DataProvider('endpointProvider')]
    public function test_missing_base_url_is_also_retryable(string $key): void
    {
        [$url, $method] = self::ENDPOINTS[$key];
        $this->klassciFails($method, KlassciUnavailableException::missingBaseUrl());

        $this->getJson($url)
            ->assertStatus(503)
            ->assertHeader('Retry-After');
    }

    /**
     * Garde de non-régression : une panne KLASSCI ne doit JAMAIS ressortir en 500.
     * Un 500 est définitif pour le client ; c'est précisément la confusion corrigée.
     *
     * @param  string  $key
     */
    #[DataProvider('endpointProvider')]
    public function test_klassci_outage_is_never_rendered_as_500(string $key): void
    {
        [$url, $method] = self::ENDPOINTS[$key];
        $this->klassciFails($method, KlassciUnavailableException::upstreamFailure(500));

        self::assertNotSame(500, $this->getJson($url)->getStatusCode());
    }

    /**
     * Contrat préservé : toute AUTRE panne garde le 500 générique existant,
     * enveloppe comprise. Le correctif ne devait toucher que le cas KLASSCI.
     */
    public function test_a_non_klassci_failure_still_returns_the_untouched_500_envelope(): void
    {
        $this->klassciFails('getEnseignantsEnrichis', new RuntimeException('panne disque'));

        $this->getJson('/api/lms/enseignants')
            ->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => 'Erreur lors du chargement des enseignants.',
                'data' => [],
            ]);
    }
}
