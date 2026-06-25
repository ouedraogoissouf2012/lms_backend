<?php

declare(strict_types=1);

namespace App\Services\Seances;

/**
 * Réaligne la PARTIE DATE des datetimes de programmation KLASSCI (heure_debut /
 * heure_fin) sur la date réelle de la séance.
 *
 * Bug source KLASSCI : `programmation.heure_debut`/`heure_fin` sont datés du jour
 * COURANT au lieu du jour de la séance, alors que `programmation.date` est correct
 * (ex. date = 2026-06-26 mais heure_debut = 2026-06-25T11:30:00Z). Conséquence :
 * tout consommateur du datetime (calendrier `start`, « séances du jour », fenêtre
 * visio) place la séance au mauvais jour. On normalise ICI, une seule fois, pour
 * tous les endpoints — l'heure et le suffixe timezone d'origine sont conservés.
 *
 * Fonction PURE, sans effet de bord, ne lève jamais.
 */
final class SeanceProgrammationNormalizer
{
    /**
     * Remplace la date d'un datetime ISO par `$date` (jour de la séance), en
     * conservant l'heure et le suffixe timezone. Si l'entrée n'est pas un datetime
     * ISO « YYYY-MM-DDT… » ou si `$date` n'est pas « YYYY-MM-DD », renvoie le
     * datetime inchangé (fail-safe).
     *
     * @param  string|null  $datetime  ex. "2026-06-25T11:30:00.000000Z"
     * @param  string|null  $date      ex. "2026-06-26"
     * @return string|null             ex. "2026-06-26T11:30:00.000000Z"
     */
    public static function alignDate(?string $datetime, ?string $date): ?string
    {
        if ($datetime === null || $date === null) {
            return $datetime;
        }
        if (strlen($datetime) < 11 || $datetime[10] !== 'T') {
            return $datetime;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return $datetime;
        }

        return $date.substr($datetime, 10);
    }
}
