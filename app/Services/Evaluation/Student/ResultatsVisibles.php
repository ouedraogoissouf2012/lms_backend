<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;

/**
 * La règle « résultats visibles » d'une évaluation (C25, D72 E2b), en un seul
 * lieu : l'élève ne voit ni sa note, ni son score, ni le commentaire de
 * l'enseignant quand `show_results` vaut false. L'enseignant les publie par
 * `PATCH /evaluations/{id}/release`, même après les remises (#1699). Elle sert
 * la liste (les deux sources), « ma copie », la remise, la page matière et
 * « Mes notes ».
 *
 * Deux formes du même masquage, chacune fixée par sa route : la liste, la
 * remise et la page matière RETIRENT les clés ({@see self::masquer()}) ;
 * « ma copie » les rend `null`.
 *
 * « Mes notes » (`/my-grades`, C30 fermé par la règle A de l'utilisateur) :
 * une évaluation aux résultats cachés n'y apporte aucune note, ni dans la liste
 * ni dans les moyennes (`StudentGradesAggregator`).
 *
 * Avant : « ma copie » et la remise masquaient la note, mais la liste
 * l'envoyait brute dans `student_submission`.
 */
final class ResultatsVisibles
{
    /**
     * Les champs que la règle masque : la note, le score, les points par
     * question d'une correction manuelle, qui suffiraient à reconstituer la
     * note, et le commentaire de l'enseignant, qui la dévoilerait (#1699).
     */
    public const CHAMPS = ['score', 'note_sur_20', 'manual_points', 'feedback'];

    public static function pour(Evaluation $evaluation): bool
    {
        return $evaluation->show_results !== false;
    }

    /**
     * La copie, sans sa note ni son score si les résultats ne sont pas
     * visibles.
     */
    public static function masquer(?EvaluationSubmission $copie, Evaluation $evaluation): ?EvaluationSubmission
    {
        if ($copie !== null && ! self::pour($evaluation)) {
            $copie->makeHidden(self::CHAMPS);
        }

        return $copie;
    }
}
