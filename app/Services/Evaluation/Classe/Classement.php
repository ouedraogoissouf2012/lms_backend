<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Classe;

/**
 * #1676 : le rang, la moyenne de la classe et le taux de réussite, à partir des moyennes générales.
 *
 * - Rang de compétition, avec ex aequo : 1, 2, 2, 4. Le rang d'un élève est 1 + le nombre de
 *   moyennes STRICTEMENT supérieures à la sienne.
 * - Un élève sans moyenne (aucune note comptée) n'est pas classé : rang `null`, et il n'entre ni
 *   dans la moyenne de la classe ni dans le taux de réussite (jamais un 0 fabriqué).
 * - Taux de réussite : la part des élèves classés dont la moyenne atteint le seuil, en pourcentage
 *   arrondi à deux décimales ; `null` quand personne n'est classé.
 */
final class Classement
{
    /**
     * @param  array<string, float|null>  $moyennes  moyenne générale, par clé d'élève
     * @return array<string, int|null> rang, par clé d'élève
     */
    public static function rangs(array $moyennes): array
    {
        $classees = array_values(array_filter($moyennes, static fn (?float $moyenne): bool => $moyenne !== null));

        $rangs = [];
        foreach ($moyennes as $cle => $moyenne) {
            $rangs[$cle] = $moyenne === null
                ? null
                : count(array_filter($classees, static fn (float $autre): bool => $autre >= $moyenne));
        }

        return $rangs;
    }

    /**
     * @param  array<string, float|null>  $moyennes
     * @return array{effectif: int, classes: int, moyenne_classe: float|null, taux_reussite: float|null}
     */
    public static function statistiques(array $moyennes, float $seuil): array
    {
        $classees = array_values(array_filter($moyennes, static fn (?float $moyenne): bool => $moyenne !== null));
        $nombre = count($classees);
        $reussis = count(array_filter($classees, static fn (float $moyenne): bool => $moyenne >= $seuil));

        return [
            'effectif' => count($moyennes),
            'classes' => $nombre,
            'moyenne_classe' => $nombre === 0 ? null : round(array_sum($classees) / $nombre, 2),
            'taux_reussite' => $nombre === 0 ? null : round(100 * $reussis / $nombre, 2),
        ];
    }
}
