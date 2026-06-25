<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seances;

use App\Services\Seances\SeanceProgrammationNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Réalignement de la date des datetimes KLASSCI (heure_debut/heure_fin) sur la
 * date de la séance — contournement du bug KLASSCI qui les date du jour courant.
 */
final class SeanceProgrammationNormalizerTest extends TestCase
{
    public function test_realigne_la_date_en_conservant_heure_et_timezone(): void
    {
        // KLASSCI : séance du 26, mais heure_debut datée du 25 (jour courant).
        $this->assertSame(
            '2026-06-26T11:30:00.000000Z',
            SeanceProgrammationNormalizer::alignDate('2026-06-25T11:30:00.000000Z', '2026-06-26'),
        );
    }

    public function test_laisse_inchange_quand_la_date_est_deja_bonne(): void
    {
        $this->assertSame(
            '2026-06-25T13:00:00.000000Z',
            SeanceProgrammationNormalizer::alignDate('2026-06-25T13:00:00.000000Z', '2026-06-25'),
        );
    }

    public function test_null_safe(): void
    {
        $this->assertNull(SeanceProgrammationNormalizer::alignDate(null, '2026-06-26'));
        $this->assertSame('2026-06-25T11:30:00Z', SeanceProgrammationNormalizer::alignDate('2026-06-25T11:30:00Z', null));
    }

    public function test_passe_l_entree_inchangee_si_non_iso(): void
    {
        // Pas un datetime ISO « YYYY-MM-DDT… » → on ne touche pas (fail-safe).
        $this->assertSame('11:30', SeanceProgrammationNormalizer::alignDate('11:30', '2026-06-26'));
        // Date cible mal formée → datetime inchangé.
        $this->assertSame('2026-06-25T11:30:00Z', SeanceProgrammationNormalizer::alignDate('2026-06-25T11:30:00Z', '26/06/2026'));
    }
}
