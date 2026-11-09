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
 * de la classe est PUBLIÉE et en phase « inscriptions ouvertes » ou « en cours ».
 *
 * Un fait daté, jamais une valeur inventée (lot 3a). Chaque statut croisé avec
 * chaque phase : la règle n'a qu'un lieu, `TrainingSession::estActive()`.
 */
final class TrainingSessionActiviteTest extends TestCase
{
    private const AUJOURD_HUI = '2026-11-09';

    /** Les cinq dates, ordonnées, qui placent la Période dans chaque phase AUJOURD_HUI. */
    private const DATES = [
        'a_venir' => ['2026-12-01', '2026-12-15', '2027-01-05', '2027-04-30', '2027-05-15'],
        'inscriptions_ouvertes' => ['2026-11-01', '2026-11-30', '2026-12-01', '2027-03-31', '2027-04-15'],
        'en_cours' => ['2026-10-01', '2026-10-20', '2026-11-01', '2027-02-28', '2027-03-15'],
        'terminee' => ['2026-06-01', '2026-06-15', '2026-07-01', '2026-10-31', '2026-11-15'],
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
            foreach (array_keys(self::DATES) as $phase) {
                $active = $statut === TrainingSessionStatus::Publiee
                    && in_array($phase, ['inscriptions_ouvertes', 'en_cours'], true);

                yield "{$statut->value}, {$phase}" => [$statut, $phase, $active];
            }
        }
    }

    #[DataProvider('statutsEtPhases')]
    public function test_la_periode_est_active_si_publiee_et_ouverte_ou_en_cours(
        TrainingSessionStatus $statut,
        string $phase,
        bool $active,
    ): void {
        Carbon::setTestNow(self::AUJOURD_HUI);
        [$ouverture, $fermeture, $debut, $fin, $certificat] = self::DATES[$phase];
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
