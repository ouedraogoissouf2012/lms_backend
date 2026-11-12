<?php

declare(strict_types=1);

namespace App\Services\Matiere;

/**
 * Les règles d'affichage de l'écran d'administration des matières, communes aux
 * deux sources ({@see AdminMatieresQueryService} pour l'école créée depuis
 * KLASSCI, {@see LocalInstitutionMatieresReader} pour l'école créée depuis le
 * LMS) : écrites une fois, elles ne peuvent pas diverger.
 */
final class AdminMatierePresentation
{
    /** Couleur d'une matière qui n'en porte pas : un réglage d'affichage, pas une mesure. */
    public const COULEUR_PAR_DEFAUT = '#6366f1';

    /** Libellé d'un nom ou d'un code absent. */
    public const SANS_VALEUR = 'N/A';

    /**
     * Totaux : on ne somme que ce qui a été réellement mesuré.
     *
     * `total_heures` vaut `null` si AUCUNE matière ne porte d'heures — sommer des
     * absences donnerait `0`, indiscernable d'un vrai « zéro heure ». `total_seances`
     * est toujours `null` : aucune des deux sources ne porte cette donnée.
     *
     * @param  array<int, array<string, mixed>>  $matieres
     * @return array<string, mixed>
     */
    public static function statistiques(array $matieres): array
    {
        $heures = [];
        foreach ($matieres as $matiere) {
            if (is_int($matiere['heures_total'] ?? null)) {
                $heures[] = $matiere['heures_total'];
            }
        }

        return [
            'total' => count($matieres),
            'total_heures' => $matieres === [] ? 0 : ($heures === [] ? null : array_sum($heures)),
            'total_seances' => null,
        ];
    }
}
