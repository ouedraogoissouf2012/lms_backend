<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

/**
 * Les moyennes de « Mes notes », en un seul lieu (D72, E4a).
 *
 * Règle unique des agrégats sans mesure : une moyenne qui n'a RIEN à moyenner vaut
 * `null`, jamais un 0 fabriqué. Un 0 dirait à l'élève qu'il a 0/20 ; `null` dit
 * qu'il n'y a pas encore de note à compter (aucune note visible, C30, ou un
 * coefficient total nul).
 */
final class Moyennes
{
    /**
     * La moyenne pondérée par coefficient, arrondie à deux décimales ; `null` si le
     * coefficient total est nul.
     *
     * @param  list<array{0: float|int|string, 1: float|int|string}>  $notesEtCoefficients
     */
    public static function ponderee(array $notesEtCoefficients): ?float
    {
        $points = 0.0;
        $coefficients = 0.0;
        foreach ($notesEtCoefficients as [$note, $coefficient]) {
            $points += (float) $note * (float) $coefficient;
            $coefficients += (float) $coefficient;
        }

        return $coefficients > 0 ? round($points / $coefficients, 2) : null;
    }

    /**
     * La moyenne simple des moyennes MESURÉES, arrondie à deux décimales ; `null` si
     * aucune ne l'est.
     *
     * @param  list<float|null>  $moyennes
     */
    public static function simple(array $moyennes): ?float
    {
        $mesurees = array_values(array_filter($moyennes, static fn (?float $moyenne): bool => $moyenne !== null));

        return $mesurees === [] ? null : round(array_sum($mesurees) / count($mesurees), 2);
    }
}
