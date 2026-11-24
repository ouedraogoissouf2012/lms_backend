<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Config;

use App\Support\Config\LecteurDEntiers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #738 — les cas limites de la lecture d'un entier de configuration, écrits UNE fois, à
 * l'endroit unique, au lieu de zéro fois dans sept copies.
 */
final class LecteurDEntiersTest extends TestCase
{
    /** @return iterable<string, array{0: mixed, 1: int}> */
    public static function positifs(): iterable
    {
        yield 'absent (null)' => [null, 7];
        yield 'zéro' => [0, 7];
        yield 'zéro en texte' => ['0', 7];
        yield 'négatif' => [-3, 7];
        yield 'négatif en texte' => ['-3', 7];
        yield 'chaîne vide' => ['', 7];
        yield 'texte illisible' => ['abc', 7];
        yield 'à moitié numérique' => ['5abc', 7];
        yield 'booléen' => [true, 7];
        yield 'entier' => [12, 12];
        yield 'entier en texte' => ['12', 12];
        yield 'décimal en texte, tronqué' => ['2.7', 2];
        yield 'décimal sous un, tronqué à zéro' => ['0.5', 7];
    }

    #[DataProvider('positifs')]
    public function test_positif(mixed $valeur, int $attendu): void
    {
        self::assertSame($attendu, (new LecteurDEntiers)->positif($valeur, 7));
    }

    /** @return iterable<string, array{0: mixed, 1: int, 2: int}> */
    public static function auMoins(): iterable
    {
        yield 'absent, plancher 1' => [null, 1, 1];
        yield 'zéro, plancher 1' => [0, 1, 1];
        yield 'zéro, plancher 0' => [0, 0, 0];
        yield 'négatif, plancher 0' => ['-4', 0, 0];
        yield 'illisible, plancher 1' => ['abc', 1, 1];
        yield 'à moitié numérique, plancher 1 (durcissement)' => ['5abc', 1, 1];
        yield 'au-dessus du plancher' => ['50', 1, 50];
        yield 'entier au-dessus du plancher' => [50, 1, 50];
    }

    #[DataProvider('auMoins')]
    public function test_au_moins(mixed $valeur, int $plancher, int $attendu): void
    {
        self::assertSame($attendu, (new LecteurDEntiers)->auMoins($valeur, $plancher));
    }
}
