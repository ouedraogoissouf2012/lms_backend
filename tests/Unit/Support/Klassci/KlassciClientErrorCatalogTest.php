<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Klassci;

use App\Support\Klassci\KlassciClientErrorCatalog;
use Tests\TestCase;

/**
 * Classification des erreurs 4xx KLASSCI — source unique partagée par les deux
 * traits de rendu (proxy et LMS).
 *
 * Elle n'existait que dans {@see \App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors}
 * (dette tracée dans son docblock) ; le trait proxy, lui, écrasait TOUT échec non-401
 * en 500 — un refus d'autorisation KLASSCI (403) se présentait donc au client comme
 * une panne du serveur LMS.
 */
final class KlassciClientErrorCatalogTest extends TestCase
{
    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function statusProvider(): array
    {
        return [
            'borne basse 400'      => [400, true],
            'borne haute 499'      => [499, true],
            '403 autorisation'     => [403, true],
            '404 introuvable'      => [404, true],
            '429 quota'            => [429, true],
            '399 hors plage'       => [399, false],
            '500 panne serveur'    => [500, false],
            '503 indisponible'     => [503, false],
            'code 0 (non HTTP)'    => [0, false],
            'négatif'              => [-1, false],
            'null'                 => [null, false],
            'flottant 403.0'       => [403.0, false],
        ];
    }

    /**
     * @dataProvider statusProvider
     */
    public function test_only_integer_client_statuses_are_relayable(mixed $status, bool $expected): void
    {
        self::assertSame($expected, KlassciClientErrorCatalog::isRelayableClientStatus($status));
    }

    /**
     * Le code d'une exception PHP n'est PAS garanti entier : PDOException — donc
     * Illuminate\Database\QueryException, elle aussi une RuntimeException — porte
     * un SQLSTATE en CHAINE. Aucun ne doit etre pris pour un statut HTTP.
     *
     * @dataProvider sqlStateProvider
     */
    public function test_sqlstate_codes_are_never_mistaken_for_http_statuses(string $sqlState): void
    {
        self::assertFalse(KlassciClientErrorCatalog::isRelayableClientStatus($sqlState));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sqlStateProvider(): array
    {
        return [
            'table absente'   => ['42S02'],
            'contrainte'      => ['23000'],
            'erreur generale' => ['HY000'],
        ];
    }

    /**
     * Le piege que la garde `is_int` neutralise, demontre sur un SQLSTATE reel :
     * PHP 8 compare une chaine non numerique a un entier en les comparant comme
     * des CHAINES, si bien que '42S02' tombe « entre » 400 et 499. Sans la garde,
     * cette chaine partirait comme code HTTP a response()->json() et la TypeError
     * naitrait DEPUIS le gestionnaire d'erreurs.
     */
    public function test_the_range_comparison_alone_would_be_fooled(): void
    {
        $sqlState = '42S02';

        self::assertTrue($sqlState >= 400 && $sqlState <= 499, 'la comparaison nue est bien trompeuse');
        self::assertFalse(KlassciClientErrorCatalog::isRelayableClientStatus($sqlState));
    }

    public function test_authorization_statuses_share_one_message(): void
    {
        self::assertSame(
            KlassciClientErrorCatalog::messageFor(401),
            KlassciClientErrorCatalog::messageFor(403),
        );
    }

    /**
     * 403 et 404 gardent des MESSAGES distincts, comme leurs statuts : les
     * identifiants de classe sont déjà énumérables via /proxy/classes (même groupe
     * de routes, jeton de service, 200) — les fusionner ne protégerait rien et
     * coûterait la précision au client.
     */
    public function test_not_found_is_distinct_from_forbidden(): void
    {
        self::assertNotSame(
            KlassciClientErrorCatalog::messageFor(403),
            KlassciClientErrorCatalog::messageFor(404),
        );
    }

    public function test_quota_has_its_own_actionable_message(): void
    {
        $message = KlassciClientErrorCatalog::messageFor(429);

        self::assertNotSame(KlassciClientErrorCatalog::messageFor(405), $message);
        self::assertStringContainsString('réessayer', mb_strtolower($message));
    }

    public function test_unlisted_status_falls_back_without_leaking(): void
    {
        self::assertSame('KLASSCI a refusé la requête.', KlassciClientErrorCatalog::messageFor(405));
    }

    /**
     * §1.2 — aucun message ne doit exposer de détail technique. Le message brut
     * du client HTTP est « Erreur API KLASSCI: 403 » : il ne doit jamais ressortir.
     */
    public function test_no_message_leaks_technical_detail(): void
    {
        foreach ([401, 403, 404, 409, 422, 429, 405, 400] as $status) {
            $message = KlassciClientErrorCatalog::messageFor($status);

            self::assertStringNotContainsString('Erreur API', $message);
            self::assertStringNotContainsString('Exception', $message);
            self::assertDoesNotMatchRegularExpression('/\b\d{3}\b/', $message, "statut {$status}");
        }
    }
}
