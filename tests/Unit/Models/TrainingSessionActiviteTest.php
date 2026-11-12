<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\TrainingSessionPhase;
use App\Enums\TrainingSessionStatus;
use App\Models\TrainingSession;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * « Classe active cette année », dans une école créée depuis le LMS : la Période
 * de la classe est PUBLIÉE et n'est pas TERMINÉE (D124).
 *
 * Un fait daté, jamais une valeur inventée (lot 3a). Chaque statut croisé avec
 * chaque jeu de dates : la règle n'a qu'un lieu, `TrainingSession::estActive()`.
 *
 * Le jeu « inscriptions closes, pas commencée » est celui qui a fait changer la
 * règle : sa phase est « à venir », comme une Période dont les inscriptions ne
 * sont pas encore ouvertes. Une classe complète qui démarre dans quinze jours
 * n'est pas inactive pour un gestionnaire.
 */
final class TrainingSessionActiviteTest extends TestCase
{
    private const AUJOURD_HUI = '2026-11-09';

    /** Pour chaque cas, la phase attendue AUJOURD_HUI et les cinq dates, ordonnées. */
    private const DATES = [
        'inscriptions pas encore ouvertes' => ['a_venir', ['2026-12-01', '2026-12-15', '2027-01-05', '2027-04-30', '2027-05-15']],
        'inscriptions closes, pas commencée' => ['a_venir', ['2026-10-01', '2026-11-01', '2026-11-24', '2027-03-31', '2027-04-15']],
        'inscriptions ouvertes' => ['inscriptions_ouvertes', ['2026-11-01', '2026-11-30', '2026-12-01', '2027-03-31', '2027-04-15']],
        'en cours' => ['en_cours', ['2026-10-01', '2026-10-20', '2026-11-01', '2027-02-28', '2027-03-15']],
        'terminée' => ['terminee', ['2026-06-01', '2026-06-15', '2026-07-01', '2026-10-31', '2026-11-15']],
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return iterable<string, array{0: TrainingSessionStatus, 1: string, 2: bool}> */
    public static function statutsEtPhases(): iterable
    {
        foreach (TrainingSessionStatus::cases() as $statut) {
            foreach (self::DATES as $cas => [$phase]) {
                $active = $statut === TrainingSessionStatus::Publiee && $phase !== 'terminee';

                yield "{$statut->value}, {$cas}" => [$statut, $cas, $active];
            }
        }
    }

    #[DataProvider('statutsEtPhases')]
    public function test_la_periode_est_active_si_publiee_et_pas_terminee(
        TrainingSessionStatus $statut,
        string $cas,
        bool $active,
    ): void {
        Carbon::setTestNow(self::AUJOURD_HUI);
        [$phase, [$ouverture, $fermeture, $debut, $fin, $certificat]] = self::DATES[$cas];
        $periode = (new TrainingSession)->forceFill([
            'status' => $statut,
            'enrollment_opens_at' => $ouverture,
            'enrollment_closes_at' => $fermeture,
            'starts_on' => $debut,
            'ends_on' => $fin,
            'certificate_available_at' => $certificat,
        ]);

        self::assertSame(TrainingSessionPhase::from($phase), $periode->phase, 'jeu de dates mal construit');
        self::assertSame($active, $periode->estActive());
    }
}
