<?php

declare(strict_types=1);

namespace App\Services\Attendances\Suivi;

/**
 * LE calcul du taux de présence (#1683, ARRÊT 1 Q2 validé) : (présent + retard) ÷ séances attendues, les absences
 * JUSTIFIÉES (`excused`) hors du dénominateur, en pourcentage arrondi. Sans séance attendue, `null` : rien n'est
 * mesuré, et rien n'est inventé à 0. L'attestation (lot 3) et le bonus d'assiduité (lot 5) le reliront ici.
 *
 * Les écrans existants qui comptent sur les lignes de connexion (statistiques d'une séance, rapport, historique,
 * tableau d'administration) ne le lisent pas encore : leur reprise change leur sens et vient à part (PR C de #1683).
 */
final class TauxDePresence
{
    /**
     * @param  int  $presences  présent + retard
     * @param  int  $absences  absences NON justifiées
     */
    public static function de(int $presences, int $absences): ?int
    {
        $attendues = $presences + $absences;

        return $attendues > 0 ? (int) round($presences / $attendues * 100) : null;
    }
}
