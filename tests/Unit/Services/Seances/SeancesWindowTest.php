<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seances;

use App\Services\Seances\SeancesWindow;
use App\Services\Seances\Sync\StaleSeanceArchiver;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * La fenêtre de dates n'était verrouillée par AUCUN test.
 *
 * Constaté de la pire façon : en réduisant `SeancesWindow` à `[aujourd'hui,
 * aujourd'hui]`, la suite entière restait verte. Une fenêtre d'un jour est
 * pourtant l'exacte pathologie que cette classe existe pour empêcher —
 * `emploi-temps` interrogé sans bornes ne rend que la semaine courante, et une
 * fenêtre trop étroite fait conclure à la synchronisation que le reste de
 * l'année a disparu de KLASSCI : {@see StaleSeanceArchiver}
 * archive alors les séances sous le motif `supprimee_klassci`.
 *
 * Ce fichier ferme ce trou.
 */
#[CoversClass(SeancesWindow::class)]
final class SeancesWindowTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * La fenêtre encadre le jour courant, des DEUX côtés.
     *
     * Une fenêtre uniquement tournée vers l'avenir masquerait l'historique ;
     * uniquement vers le passé, l'enseignant ne verrait pas ses cours à venir.
     */
    public function test_the_window_straddles_today_on_both_sides(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-06 10:00:00'));

        [$debut, $fin] = SeancesWindow::rolling();

        self::assertSame('2026-03-06', $debut);
        self::assertSame('2026-09-06', Carbon::now()->format('Y-m-d'));
        self::assertSame('2027-03-06', $fin);
    }

    /**
     * Le cœur du sujet : la fenêtre doit couvrir une année scolaire entière.
     * Un intervalle plus étroit est un défaut, pas un réglage.
     */
    public function test_the_window_spans_a_full_school_year(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-06 10:00:00'));

        [$debut, $fin] = SeancesWindow::rolling();

        $jours = Carbon::parse($debut)->diffInDays(Carbon::parse($fin));

        self::assertGreaterThanOrEqual(
            365,
            $jours,
            'Une fenêtre de moins d\'un an ferait conclure à la synchronisation que les séances hors fenêtre ont été supprimées de KLASSCI.',
        );
    }

    /**
     * Les deux bornes sont au format que KLASSCI attend, et dans le bon ordre.
     */
    public function test_both_bounds_are_iso_dates_in_chronological_order(): void
    {
        [$debut, $fin] = SeancesWindow::rolling();

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $debut);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $fin);
        self::assertLessThan($fin, $debut);
    }

    /**
     * La fenêtre suit le jour courant : consultée en janvier ou en juin, elle
     * reste centrée sur l'utilisateur, jamais figée sur une année codée en dur.
     */
    public function test_the_window_follows_the_current_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 08:00:00'));
        [$debutJanvier] = SeancesWindow::rolling();

        Carbon::setTestNow(Carbon::parse('2026-06-15 08:00:00'));
        [$debutJuin] = SeancesWindow::rolling();

        self::assertNotSame($debutJanvier, $debutJuin);
        self::assertSame('2025-07-15', $debutJanvier);
        self::assertSame('2025-12-15', $debutJuin);
    }
}
