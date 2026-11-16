<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Evaluation;

use App\Services\Evaluation\DureeDeLaCopie;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La durée d'une copie en minutes, la minute entamée comptée (#901), aux bornes.
 */
final class DureeDeLaCopieTest extends TestCase
{
    /** @return iterable<string, array{0: int, 1: int}> */
    public static function durees(): iterable
    {
        yield 'zéro seconde' => [0, 0];
        yield '20 secondes : la minute entamée' => [20, 1];
        yield '60 secondes : une minute pile' => [60, 1];
        yield '61 secondes : la deuxième minute entamée' => [61, 2];
        yield '25 min 10 s' => [25 * 60 + 10, 26];
    }

    #[DataProvider('durees')]
    public function test_la_minute_entamee_est_comptee(int $secondes, int $minutes): void
    {
        $debut = Carbon::parse('2026-11-16 08:00:00');

        self::assertSame($minutes, DureeDeLaCopie::enMinutes($debut, $debut->copy()->addSeconds($secondes)));
    }

    public function test_une_duree_negative_est_inconnue(): void
    {
        $debut = Carbon::parse('2026-11-16 08:00:00');

        self::assertNull(DureeDeLaCopie::enMinutes($debut, $debut->copy()->subSecond()));
    }

    public function test_sans_debut_ou_sans_remise_la_duree_est_inconnue(): void
    {
        $instant = Carbon::parse('2026-11-16 08:00:00');

        self::assertNull(DureeDeLaCopie::enMinutes(null, $instant));
        self::assertNull(DureeDeLaCopie::enMinutes($instant, null));
        self::assertNull(DureeDeLaCopie::enMinutes(null, null));
    }
}
