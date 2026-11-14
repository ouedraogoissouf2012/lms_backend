<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\BusinessException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le statut HTTP d'un refus métier : son code s'il est un statut d'erreur
 * (400 à 599), sinon 422. UNE règle, portée par l'exception, au lieu d'une copie
 * privée par contrôleur.
 */
final class BusinessExceptionStatutTest extends TestCase
{
    /** @return iterable<string, array{int, int}> */
    public static function codes(): iterable
    {
        yield 'conflit' => [409, 409];
        yield 'introuvable' => [404, 404];
        yield 'borne basse' => [400, 400];
        yield 'borne haute' => [599, 599];
        yield 'code par défaut' => [0, 422];
        yield 'succès' => [200, 422];
        yield 'sous la borne' => [399, 422];
        yield 'au-delà de la borne' => [600, 422];
        yield 'négatif' => [-1, 422];
    }

    #[DataProvider('codes')]
    public function test_le_statut_http_d_un_refus_metier(int $code, int $statut): void
    {
        self::assertSame($statut, (new BusinessException('refus', $code))->statutHttp());
    }
}
