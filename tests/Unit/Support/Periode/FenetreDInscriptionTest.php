<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Periode;

use App\Support\Periode\FenetreDInscription;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #1208 — la fenêtre d'inscription d'une Période, au JOUR, bornes INCLUSIVES.
 *
 * Une seule comparaison, lue par la porte des codes et par la phase. Une borne nulle n'est
 * ni « pas encore ouverte » ni « déjà fermée » : chaque lecteur dit ce que le nul vaut pour lui.
 */
final class FenetreDInscriptionTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return iterable<string, array{0: string, 1: ?string, 2: ?string, 3: bool, 4: bool}> */
    public static function cas(): iterable
    {
        // maintenant, ouverture, fermeture, pasEncoreOuverte, dejaFermee
        yield 'la veille de l’ouverture, à 23:59:59' => ['2026-11-14 23:59:59', '2026-11-15', '2026-11-20', true, false];
        yield 'le jour de l’ouverture, à minuit' => ['2026-11-15 00:00:00', '2026-11-15', '2026-11-20', false, false];
        yield 'au milieu de la fenêtre' => ['2026-11-17 12:00:00', '2026-11-15', '2026-11-20', false, false];
        yield 'le jour de la fermeture, à 23:59:59' => ['2026-11-20 23:59:59', '2026-11-15', '2026-11-20', false, false];
        yield 'le lendemain de la fermeture, à minuit' => ['2026-11-21 00:00:00', '2026-11-15', '2026-11-20', false, true];
        yield 'sans ouverture, avant la fermeture' => ['2026-11-01 08:00:00', null, '2026-11-20', false, false];
        yield 'sans fermeture, après l’ouverture' => ['2027-06-01 08:00:00', '2026-11-15', null, false, false];
        yield 'sans aucune borne' => ['2026-11-17 08:00:00', null, null, false, false];
    }

    #[DataProvider('cas')]
    public function test_la_fenetre_se_lit_au_jour_bornes_incluses(
        string $maintenant,
        ?string $ouverture,
        ?string $fermeture,
        bool $pasEncoreOuverte,
        bool $dejaFermee,
    ): void {
        Carbon::setTestNow($maintenant);

        $fenetre = new FenetreDInscription(
            $ouverture === null ? null : Carbon::parse($ouverture),
            $fermeture === null ? null : Carbon::parse($fermeture),
        );

        self::assertSame($pasEncoreOuverte, $fenetre->pasEncoreOuverte(), 'pasEncoreOuverte');
        self::assertSame($dejaFermee, $fenetre->dejaFermee(), 'dejaFermee');
    }
}
