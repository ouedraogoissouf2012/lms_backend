<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Exceptions\BusinessException;
use Illuminate\Cache\RateLimiter;
use Psr\Log\LoggerInterface;

/**
 * Les plafonds de la porte anonyme ne comptent que les ÉCHECS (#915).
 *
 * Une inscription réussie n'est jamais un essai de trop : une classe entière qui
 * s'inscrit depuis le wifi de l'école, derrière UNE adresse, le même matin, ne
 * doit rien rencontrer. Un code faux (inconnu, retiré, ou d'une école dont la
 * liste vient de KLASSCI : le même 404) est compté trois fois :
 *
 * | Borne | Au-delà |
 * |---|---|
 * | 5 par minute, par adresse | `ip_quota_exceeded` |
 * | 30 par jour, par adresse | `ip_daily_cap_reached` |
 * | 200 par jour, par école, PARTAGÉ avec la porte authentifiée | `institution_cap_reached` |
 *
 * Le plafond d'école remplace l'ancien plafond GLOBAL de la plateforme : il
 * freine une énumération répartie sur de nombreuses adresses, sans qu'une
 * adresse puisse éteindre les autres écoles.
 *
 * @see docs/adr/2026-11-16-915-01-plafonds-de-la-porte-anonyme.md
 */
final class EchecsDeLaPorteAnonyme
{
    private const PAR_MINUTE = 5;

    private const PAR_JOUR_ADRESSE = 30;

    private const UNE_MINUTE = 60;

    private const UN_JOUR = 86_400;

    public function __construct(
        private readonly RateLimiter $limiteur,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws BusinessException 429 quand une borne est atteinte
     */
    public function verifier(int $institution, string $adresse): void
    {
        $bornes = [
            [self::cleMinute($adresse), self::PAR_MINUTE, 'ip_quota_exceeded',
                'Trop de codes erronés depuis cette adresse. Réessayez dans une minute.'],
            [self::cleJour($adresse), self::PAR_JOUR_ADRESSE, 'ip_daily_cap_reached',
                'Trop de codes erronés aujourd\'hui depuis cette adresse. Réessayez demain.'],
            [RejoindreParCodeService::cleDesEchecs($institution), RejoindreParCodeService::ECHECS_PAR_JOUR, 'institution_cap_reached',
                'Trop de codes erronés aujourd\'hui dans cet établissement. Réessayez demain.'],
        ];

        foreach ($bornes as [$cle, $maximum, $motif, $message]) {
            if ($this->limiteur->tooManyAttempts($cle, $maximum)) {
                $this->logger->warning('Inscription par code : plafond d\'échecs atteint', [
                    'institution_id' => $institution,
                    'motif' => $motif,
                ]);

                throw new BusinessException($message, 429, reason: $motif);
            }
        }
    }

    /** Un code faux de plus, pour l'adresse et pour l'école. */
    public function compter(int $institution, string $adresse): void
    {
        $this->limiteur->hit(self::cleMinute($adresse), self::UNE_MINUTE);
        $this->limiteur->hit(self::cleJour($adresse), self::UN_JOUR);
        $this->limiteur->hit(RejoindreParCodeService::cleDesEchecs($institution), self::UN_JOUR);
    }

    /** Publique pour que les tests la LISENT au lieu de la recopier. */
    public static function cleMinute(string $adresse): string
    {
        return 'inscriptions-echecs-minute|ip:'.$adresse;
    }

    /** Publique pour que les tests la LISENT au lieu de la recopier. */
    public static function cleJour(string $adresse): string
    {
        return 'inscriptions-echecs-jour|ip:'.$adresse;
    }
}
