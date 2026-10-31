<?php

declare(strict_types=1);

namespace Tests\Feature\Klassci;

use App\Exceptions\KlassciUnavailableException;
use App\Models\Institution;
use App\Services\Klassci\KlassciCircuitBreaker;
use App\Services\Klassci\KlassciHttpClient;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `KlassciHttpClient` : un corps 2xx NON vide qui n'est pas un tableau JSON
 * n'est pas une réponse vide.
 *
 * Il devenait `[]`, et chaque appelant le lisait comme « aucune donnée » (un
 * effectif à 0, une liste vide), que le cache gardait ensuite comme une donnée.
 * En lecture, il lève désormais l'indisponibilité, comme un 5xx, sans compter
 * comme un succès pour le circuit. Un tableau JSON, même vide, un 204 et un
 * corps vide restent `[]`. Une écriture aboutie n'est jamais signalée en échec.
 *
 * Fichier distinct de KlassciHttpClientTest, qui traverse le vrai transport et
 * se saute sous Windows : ici, tout passe par `Http::fake`, une closure par test.
 *
 * @see app/Services/Klassci/KlassciHttpClient.php
 */
final class KlassciCorpsIllisibleTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://ecole.klassci.test/api/lms';

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantManager::class)->set(
            Institution::factory()->create(['klassci_api_url' => self::BASE_URL, 'is_active' => true]),
        );
    }

    public function test_une_lecture_au_corps_illisible_leve_l_indisponibilite(): void
    {
        $this->klassciRepond(['<html>maintenance</html>']);

        $this->expectException(KlassciUnavailableException::class);

        $this->client()->executeHttp('GET', 'classes/42', [], 'jeton');
    }

    /** @return iterable<string, array{0: string, 1: int, 2: array<mixed>}> */
    public static function corpsSansDonnee(): iterable
    {
        yield 'liste JSON vide (liste nue légitime)' => ['[]', 200, []];
        yield 'objet JSON vide' => ['{}', 200, []];
        yield 'corps vide' => ['', 200, []];
        yield '204 sans contenu' => ['', 204, []];
        yield 'enveloppe' => ['{"success":true,"data":[1]}', 200, ['success' => true, 'data' => [1]]];
    }

    /** @param  array<mixed>  $attendu */
    #[DataProvider('corpsSansDonnee')]
    public function test_un_corps_lisible_ou_vide_reste_une_reponse(string $corps, int $statut, array $attendu): void
    {
        $this->klassciRepond([$corps], $statut);

        self::assertSame($attendu, $this->client()->executeHttp('GET', 'classes/42', [], 'jeton'));
    }

    /** L'écriture a abouti chez KLASSCI : la signaler en échec serait faux. */
    public function test_une_ecriture_au_corps_illisible_n_est_pas_un_echec(): void
    {
        $this->klassciRepond(['OK']);

        self::assertSame([], $this->client()->executeHttp('POST', 'cours/7/presences', ['present' => [1]], 'jeton'));
    }

    /**
     * Un corps illisible ne remet pas le compteur du circuit à zéro. Seuil à 2 :
     * deux 5xx encadrant un corps illisible ouvrent le circuit. Avant, le corps
     * illisible comptait comme un succès, et le second 5xx repartait de 1.
     */
    public function test_un_corps_illisible_ne_compte_pas_comme_un_succes_du_circuit(): void
    {
        config(['services.klassci.circuit_breaker_enabled' => true, 'services.klassci.circuit_breaker_failures' => 2]);
        $this->klassciRepond(['{"message":"panne"}', '<html>maintenance</html>', '{"message":"panne"}'], 503);

        foreach ([1, 2, 3] as $_) {
            try {
                $this->client()->executeHttp('GET', 'classes/42', [], 'jeton');
            } catch (KlassciUnavailableException) {
                // Attendu pour chacun des trois appels.
            }
        }

        self::assertTrue(app(KlassciCircuitBreaker::class)->isOpen());
    }

    /**
     * Une seule closure : chaque appel consomme le corps suivant. Dans le test du
     * circuit, le 2e corps (illisible) est rendu en 200, les autres au statut donné.
     *
     * @param  non-empty-list<string>  $corps
     */
    private function klassciRepond(array $corps, int $statut = 200): void
    {
        $appel = 0;
        Http::fake(function () use ($corps, $statut, &$appel) {
            $i = min($appel++, count($corps) - 1);
            $code = $statut >= 500 && str_starts_with($corps[$i], '<html>') ? 200 : $statut;

            return Http::response($corps[$i], $code);
        });
    }

    private function client(): KlassciHttpClient
    {
        return app(KlassciHttpClient::class);
    }
}
