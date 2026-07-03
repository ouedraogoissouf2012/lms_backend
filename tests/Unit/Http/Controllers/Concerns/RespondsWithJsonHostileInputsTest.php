<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers\Concerns;

use App\Http\Controllers\Concerns\RespondsWithJson;
use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Http\JsonEnvelopeProbe;
use Tests\TestCase;

/**
 * Axe #1 — Entrées hostiles du trait `RespondsWithJson`.
 *
 * Documente le comportement de rupture MESURÉ (PHP 8.3, Laravel 12) face aux
 * entrées qu'un controller peut réellement produire : UTF-8 invalide sorti
 * d'une DB latin1, flottants non finis issus d'un calcul de moyenne, objets
 * non sérialisables, statuts HTTP hors bornes, récursion.
 *
 * Deux familles :
 * 1. **Fail-fast** — `response()->json()` jette `InvalidArgumentException`
 *    AVANT tout envoi au client : le handler global produit un 500 générique,
 *    aucun JSON corrompu ne part sur le réseau. C'est le comportement voulu.
 * 2. **Corruption silencieuse** — cas où AUCUNE exception n'est levée mais où
 *    le JSON émis trahit le contrat (Closure → `{}`, DateTime brut → structure
 *    interne PHP, enveloppes sémantiquement incohérentes). Ces tests figent le
 *    comportement actuel pour qu'un futur garde-fou soit un changement CONSCIENT.
 *
 * Chaque assertion reflète une sortie observée par sonde, pas une intuition.
 */
#[CoversTrait(RespondsWithJson::class)]
final class RespondsWithJsonHostileInputsTest extends TestCase
{
    private JsonEnvelopeProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->probe = new JsonEnvelopeProbe;
    }

    // ----- Famille 1 : fail-fast (exception avant toute émission) -----

    public function test_data_utf8_invalide_jette_avant_emission(): void
    {
        // Scénario réel : colonne DB en latin1 ("café" stocké \xE9) renvoyée telle quelle.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed UTF-8');

        $this->probe->success(['name' => "\xB1\x31"]);
    }

    public function test_message_utf8_invalide_jette_avant_emission(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed UTF-8');

        $this->probe->success(null, "caf\xE9 latin1");
    }

    /**
     * @return array<string, array{float}>
     */
    public static function nonFiniteFloatProvider(): array
    {
        // Scénario réel : moyenne = total / count(0) → INF ; 0/0 en float → NAN.
        return [
            'INF' => [INF],
            '-INF' => [-INF],
            'NAN' => [NAN],
        ];
    }

    #[DataProvider('nonFiniteFloatProvider')]
    public function test_flottant_non_fini_jette(float $hostile): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Inf and NaN cannot be JSON encoded');

        $this->probe->success(['average' => $hostile]);
    }

    public function test_resource_dans_data_jette(): void
    {
        $handle = fopen('php://memory', 'r');
        self::assertIsResource($handle);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Type is not supported');

            $this->probe->success(['handle' => $handle]);
        } finally {
            fclose($handle);
        }
    }

    public function test_reference_circulaire_jette(): void
    {
        $node = [];
        $node['self'] = &$node;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recursion detected');

        $this->probe->success($node);
    }

    public function test_profondeur_500_passe_mais_600_jette(): void
    {
        // json_encode plafonne à 512 niveaux (enveloppe {success,data} comprise).
        $response = $this->probe->success(self::nest(500));
        self::assertSame(200, $response->status());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stack depth exceeded');

        $this->probe->success(self::nest(600));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidHttpStatusProvider(): array
    {
        return [
            'statut 99 (sous la borne 100)' => [99],
            'statut 600 (au-dessus de 599)' => [600],
            'statut 0' => [0],
            'statut négatif' => [-1],
            'statut 1000' => [1000],
        ];
    }

    #[DataProvider('invalidHttpStatusProvider')]
    public function test_statut_http_hors_bornes_jette(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not valid');

        $this->probe->error('Erreur', $status);
    }

    public function test_json_serializable_qui_jette_propage_l_exception_brute(): void
    {
        // Le trait n'ajoute AUCUN garde-fou : l'exception (et son message interne)
        // remonte telle quelle. C'est le handler global qui doit la convertir en
        // 500 générique — jamais ce message ne doit atteindre le client (§1.2).
        $bomb = new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                throw new RuntimeException('détail interne sensible');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('détail interne sensible');

        $this->probe->success($bomb);
    }

    // ----- Famille 2 : corruption silencieuse (aucune exception) -----

    public function test_closure_dans_data_est_encodee_silencieusement_en_objet_vide(): void
    {
        // PIÈGE MESURÉ : une Closure ne jette PAS — elle devient {} dans le JSON.
        // Un controller qui oublie d'appeler ->toArray() ou passe un callable
        // par erreur émet un 200 avec un payload vide, sans aucun signal d'échec.
        $response = $this->probe->success(['callback' => fn (): int => 1]);

        self::assertSame(200, $response->status());
        self::assertSame(['success' => true, 'data' => ['callback' => []]], $response->getData(true));
    }

    public function test_datetime_brut_fuit_la_structure_interne_php(): void
    {
        // PIÈGE MESURÉ : contrairement à Carbon (→ chaîne ISO-8601), un DateTime
        // natif est sérialisé comme {date, timezone_type, timezone} — un détail
        // d'implémentation PHP exposé au client. Toujours passer par Carbon.
        $response = $this->probe->success(
            ['at' => new DateTime('2026-07-02 12:00:00', new DateTimeZone('Asia/Kathmandu'))],
        );

        self::assertSame(
            [
                'date' => '2026-07-02 12:00:00.000000',
                'timezone_type' => 3,
                'timezone' => 'Asia/Kathmandu',
            ],
            $response->getData(true)['data']['at'],
        );
    }

    public function test_enveloppe_succes_avec_statut_5xx_n_est_pas_gardee(): void
    {
        // Incohérence sémantique permise : {success:true} + HTTP 500. Le trait
        // fait confiance au caller (contrat DRY-only, pas de validation croisée).
        $response = $this->probe->success(['a' => 1], '', 500);

        self::assertSame(500, $response->status());
        self::assertTrue($response->getData(true)['success']);
    }

    public function test_enveloppe_erreur_avec_statut_2xx_n_est_pas_gardee(): void
    {
        $response = $this->probe->error('tout va bien', 200);

        self::assertSame(200, $response->status());
        self::assertFalse($response->getData(true)['success']);
    }

    public function test_statut_204_avec_body_est_accepte_a_la_construction(): void
    {
        // RFC 9110 §15.3.5 : 204 = "No Content". La construction accepte pourtant
        // un body ; Symfony ne le retire qu'au moment de prepare()/send().
        $response = $this->probe->success(['a' => 1], '', 204);

        self::assertSame(204, $response->status());
        self::assertSame(['success' => true, 'data' => ['a' => 1]], $response->getData(true));
    }

    /**
     * Construit un tableau imbriqué de $levels niveaux : nest(2) = ['n' => ['n' => 'leaf']].
     */
    private static function nest(int $levels): array
    {
        $value = 'leaf';
        for ($i = 0; $i < $levels; $i++) {
            $value = ['n' => $value];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
