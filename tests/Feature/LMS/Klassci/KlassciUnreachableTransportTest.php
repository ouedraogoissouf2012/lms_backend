<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Klassci;

use App\Exceptions\KlassciUnavailableException;
use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\KlassciHttpClient;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Quand KLASSCI ne répond plus du tout, l'utilisateur doit l'apprendre (#685).
 *
 * ## Le défaut corrigé
 *
 * Constaté en production le 2026-09-03 : KLASSCI a cessé de répondre sur le
 * port 80, et `GET /api/lms/seances/my-teaching` renvoyait **500 « Une erreur
 * est survenue. »**. L'enseignant voyait une liste vide — donc aucun bouton
 * visio — et concluait que la fonctionnalité n'était pas déployée.
 *
 * ```
 * cURL error 28: Failed to connect to presentation.klassci.com port 80
 *   after 2001 ms: Timeout
 * ```
 *
 * ## L'asymétrie qui en était la cause
 *
 * Le traitement était **inversé par rapport à la gravité** :
 *
 * | Panne | Avant | Après |
 * |---|---|---|
 * | KLASSCI répond `500` | 503 + `Retry-After` + message exploitable | inchangé |
 * | KLASSCI ne répond **pas** | **500 générique, aucun signal** | 503 + `Retry-After` |
 *
 * `KlassciHttpClient` relançait la `ConnectionException` telle quelle. Or
 * celle-ci descend de `HttpClientException`, donc d'`Exception` — et **non** de
 * `RuntimeException`. Elle échappait donc au `catch (RuntimeException)` des
 * contrôleurs LMS, seul chemin menant à la réponse canonique, et finissait dans
 * le fourre-tout générique.
 *
 * Le chemin **proxy** traitait pourtant déjà ce cas correctement
 * (`RendersKlassciProxyErrors`), ce qui laissait deux règles divergentes pour la
 * même panne. La correction est faite à la source — dans le client — pour que
 * tout appelant en bénéficie, plutôt qu'en ajoutant une troisième copie.
 *
 * @see KlassciHttpClient
 * @see KlassciUnavailableException::transportFailure()
 */
final class KlassciUnreachableTransportTest extends TestCase
{
    use RefreshDatabase;

    /** Endpoints adossés au dashboard enseignant KLASSCI. */
    private const ENDPOINTS = [
        'my-matieres' => '/api/lms/teacher/my-matieres',
        'my-teaching' => '/api/lms/seances/my-teaching',
    ];

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function endpointProvider(): array
    {
        return [
            'my-matieres' => ['my-matieres'],
            'my-teaching' => ['my-teaching'],
        ];
    }

    /**
     * LE test du défaut : un écran vide devient une panne annoncée.
     */
    #[DataProvider('endpointProvider')]
    public function test_an_unreachable_klassci_answers_503_not_a_generic_500(string $key): void
    {
        $this->actingAsTeacher();
        $this->klassciTransportFails();

        $response = $this->getJson(self::ENDPOINTS[$key]);

        $response->assertStatus(503);
        $response->assertJsonPath('success', false);
    }

    /**
     * Le message doit dire à l'utilisateur quoi faire. Un « Une erreur est
     * survenue. » ne se distingue pas d'un bug du LMS lui-même.
     */
    #[DataProvider('endpointProvider')]
    public function test_the_answer_tells_the_user_what_is_happening(string $key): void
    {
        $this->actingAsTeacher();
        $this->klassciTransportFails();

        $message = (string) $this->getJson(self::ENDPOINTS[$key])->json('message');

        self::assertSame(KlassciUnavailableException::CLIENT_MESSAGE, $message);
        self::assertStringNotContainsString('Une erreur est survenue', $message);
    }

    /**
     * Sans `Retry-After`, le client traite une panne temporaire comme
     * définitive — c'est la raison d'être de la réponse canonique.
     */
    #[DataProvider('endpointProvider')]
    public function test_the_answer_carries_a_retry_after_header(string $key): void
    {
        $this->actingAsTeacher();
        $this->klassciTransportFails();

        $this->getJson(self::ENDPOINTS[$key])
            ->assertHeader('Retry-After', (string) KlassciUnavailableException::retryAfterSeconds());
    }

    /**
     * Le compte est authentifié et KLASSCI est en panne : rien ne doit suggérer
     * au client de déconnecter l'utilisateur.
     */
    #[DataProvider('endpointProvider')]
    public function test_an_outage_never_looks_like_an_expired_session(string $key): void
    {
        $this->actingAsTeacher();
        $this->klassciTransportFails();

        $response = $this->getJson(self::ENDPOINTS[$key]);
        $message = (string) $response->json('message');

        self::assertNotSame(401, $response->getStatusCode());
        self::assertStringNotContainsStringIgnoringCase('reconnecter', $message);
        self::assertStringNotContainsStringIgnoringCase('expirée', $message);
    }

    private function actingAsTeacher(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
            'klassci_token' => 'jeton-klassci-valide',
        ]));
    }

    /**
     * La panne de transport telle que `KlassciHttpClient` la traduit désormais.
     *
     * Le test se place APRÈS la traduction, au niveau du contrat que les
     * contrôleurs consomment. La traduction elle-même est verrouillée par
     * `KlassciHttpClientTest`.
     */
    private function klassciTransportFails(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->andThrow(
                KlassciUnavailableException::transportFailure(
                    new ConnectionException('cURL error 28: Timeout'),
                ),
            );
        });
    }
}
