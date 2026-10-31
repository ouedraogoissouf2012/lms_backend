<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Klassci;

use App\Services\Klassci\KlassciCacheTtl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La durée de cache d'une réponse KLASSCI, sans store.
 *
 * EchecKlassciEnCacheTest prouve la règle de bout en bout, mais par `travel()` :
 * la jambe CI redis, qui expire sur l'horloge réelle, saute ces cas. Ce test
 * pur garde la règle sous les trois jambes. Mesuré : la falsification « l'échec
 * gardé au TTL » ne rougissait que les jambes database et mysql.
 */
#[CoversClass(KlassciCacheTtl::class)]
final class KlassciCacheTtlTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, int, int}>
     */
    public static function reponses(): iterable
    {
        yield 'échec déclaré : le délai annoncé' => [['success' => false, 'message' => 'x'], 300, 30];
        yield 'échec déclaré, TTL plus court que le délai : le TTL' => [['success' => false], 10, 10];
        yield 'enveloppe valide : son TTL' => [['success' => true, 'data' => []], 300, 300];
        yield 'liste nue, sans clé success : une donnée' => [[['id' => 1]], 300, 300];
        yield 'réponse vide : une donnée' => [[], 300, 300];
        yield 'success non booléen : pas un échec déclaré' => [['success' => 0], 300, 300];
    }

    #[DataProvider('reponses')]
    public function test_la_duree_depend_de_la_reponse(mixed $reponse, int $ttl, int $attendu): void
    {
        config()->set('services.klassci.retry_after', 30);

        self::assertSame($attendu, KlassciCacheTtl::pour($reponse, $ttl));
    }

    public function test_un_echec_declare_suit_le_delai_configure(): void
    {
        config()->set('services.klassci.retry_after', 45);

        self::assertSame(45, KlassciCacheTtl::pour(['success' => false], 300));
    }
}
