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
 * Non-régression : une erreur RENVOYÉE par KLASSCI ne doit jamais être présentée
 * comme un jeton manquant.
 *
 * ## Le défaut corrigé
 *
 * Les contrôleurs LMS attrapaient `RuntimeException` — que lève aussi
 * `KlassciHttpClient` sur toute réponse 4xx de KLASSCI — et répondaient
 * invariablement `401 « Token KLASSCI non trouvé »`.
 *
 * Reproduit avec le compte `superadmin` d'une école KLASSCI : n'ayant pas de
 * profil enseignant, KLASSCI répond `404 « Profil enseignant introuvable dans la
 * table esbtp_teachers »`. Le LMS traduisait ce 404 en 401 ; le frontend y lisait
 * une session expirée et déconnectait un utilisateur parfaitement authentifié,
 * en boucle, sans jamais afficher la vraie cause.
 *
 * ## Ce que ces tests verrouillent
 *
 * Le statut sortant doit distinguer trois causes distinctes. Un test qui
 * n'asserterait que le message laisserait re-fusionner les branches : c'est le
 * **code HTTP** qui pilote la déconnexion côté client, donc c'est lui qu'on fige.
 *
 * @see app/Http/Controllers/API/Concerns/RendersKlassciBackedErrors.php
 * @see app/Exceptions/MissingKlassciTokenException.php
 */
final class KlassciErrorVsMissingTokenTest extends TestCase
{
    use RefreshDatabase;

    /** Endpoints adossés au dashboard enseignant KLASSCI. */
    private const ENDPOINTS = [
        'my-matieres' => ['/api/lms/teacher/my-matieres', 'me/teacher-dashboard'],
        'my-teaching' => ['/api/lms/seances/my-teaching', 'me/teacher-dashboard'],
    ];

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
    }

    private function actingAsTeacher(?string $token = 'jeton-klassci-valide'): void
    {
        Sanctum::actingAs(User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
            'klassci_id' => 555,
            'klassci_token' => $token,
        ]));
    }

    /**
     * Fait échouer le 1er appel KLASSCI des deux services avec l'exception fournie.
     */
    private function klassciFails(\Throwable $e): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($e): void {
            $mock->shouldReceive('requestWithUserToken')->andThrow($e);
        });
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

    // ───────── Le défaut historique : 404 KLASSCI présenté comme 401 ─────────

    #[DataProvider('endpointProvider')]
    public function test_klassci_404_is_relayed_as_404_not_as_expired_session(string $key): void
    {
        [$url] = self::ENDPOINTS[$key];
        $this->actingAsTeacher();

        // Forme exacte levée par KlassciHttpClient:164 sur une réponse 4xx.
        $this->klassciFails(new RuntimeException('Erreur API KLASSCI: 404', 404));

        $response = $this->getJson($url);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString(
            'Aucune donnée KLASSCI pour ce compte',
            (string) $response->json('message'),
        );
    }

    /**
     * Le compte est authentifié : rien ne doit suggérer au client de le déconnecter.
     */
    #[DataProvider('endpointProvider')]
    public function test_klassci_404_never_mentions_reconnecting(string $key): void
    {
        [$url] = self::ENDPOINTS[$key];
        $this->actingAsTeacher();
        $this->klassciFails(new RuntimeException('Erreur API KLASSCI: 404', 404));

        $message = (string) $this->getJson($url)->json('message');

        $this->assertStringNotContainsStringIgnoringCase('reconnecter', $message);
        $this->assertStringNotContainsStringIgnoringCase('expirée', $message);
    }

    #[DataProvider('endpointProvider')]
    public function test_klassci_403_is_relayed_as_403(string $key): void
    {
        [$url] = self::ENDPOINTS[$key];
        $this->actingAsTeacher();
        $this->klassciFails(new RuntimeException('Erreur API KLASSCI: 403', 403));

        $this->getJson($url)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Accès refusé par KLASSCI pour ce compte.');
    }

    // ───────── Le jeton réellement absent reste un 401 ─────────

    #[DataProvider('endpointProvider')]
    public function test_missing_token_still_returns_401(string $key): void
    {
        [$url] = self::ENDPOINTS[$key];
        $this->actingAsTeacher(token: null);

        $this->getJson($url)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Session KLASSCI expirée. Veuillez vous reconnecter.');
    }

    // ───────── La panne KLASSCI reste un 503 retryable ─────────

    /**
     * Garde-fou : `KlassciUnavailableException` étend `RuntimeException` et porte
     * un code 5xx. Sans traitement explicite AVANT le relais de statut, le trait
     * la relaierait en 5xx nu — perdant l'en-tête `Retry-After` et le message
     * canonique, ce qui transforme une panne temporaire en erreur définitive.
     */
    #[DataProvider('endpointProvider')]
    public function test_klassci_outage_returns_503_with_retry_after(string $key): void
    {
        [$url] = self::ENDPOINTS[$key];
        $this->actingAsTeacher();
        $this->klassciFails(KlassciUnavailableException::upstreamFailure(502));

        $response = $this->getJson($url);

        $response->assertStatus(503);
        $response->assertHeader('Retry-After');
        $response->assertJsonPath('message', KlassciUnavailableException::CLIENT_MESSAGE);
    }
}
