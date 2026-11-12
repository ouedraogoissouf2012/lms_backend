<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;

/**
 * La règle « résultats visibles » d'une évaluation (C25, D72 E2b), en un seul
 * lieu : l'élève ne voit ni sa note ni son score quand `show_results` vaut
 * false. Elle sert la liste (les deux sources), « ma copie », la remise et la
 * page matière.
 *
 * Deux formes du même masquage, chacune fixée par sa route : la liste, la
 * remise et la page matière RETIRENT les clés ({@see self::masquer()}) ;
 * « ma copie » les rend `null`.
 *
 * C25 est donc fermé sur ces quatre routes, PAS sur `/my-grades` :
 * `StudentGradesAggregator` livre encore `note_sur_20` quel que soit
 * `show_results`. C'est C30, ouvert, traité avec E4 (décision produit :
 * bulletin, ou affichage immédiat).
 *
 * Avant : « ma copie » et la remise masquaient la note, mais la liste
 * l'envoyait brute dans `student_submission`.
 */
final class ResultatsVisibles
{
    /**
     * Les champs que la règle masque : la note, le score, et les points par
     * question d'une correction manuelle, qui suffiraient à reconstituer la
     * note.
     */
    public const CHAMPS = ['score', 'note_sur_20', 'manual_points'];

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
