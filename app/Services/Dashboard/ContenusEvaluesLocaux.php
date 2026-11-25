<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Evaluation;
use App\Models\User;

/**
 * Volet autonome : l'évaluation porte son auteur (`enseignant_id`) et sa classe et sa matière
 * LOCALES (`LocalEvaluationProgramming`). Une seule requête, bornée à l'école explicitement.
 */
final class ContenusEvaluesLocaux implements ContenusEvaluesDeLEnseignant
{
    public function de(User $enseignant, int $ecole): array
    {
        $lignes = Evaluation::query()
            ->withoutGlobalScope('institution')
            ->where('institution_id', $ecole)
            ->where('enseignant_id', $enseignant->id)
            ->get(['classe_id', 'matiere_id']);

        return [
            'evaluations' => $lignes->count(),
            'classes' => IdsLocaux::de($lignes->pluck('classe_id')->all()),
            'matieres' => IdsLocaux::de($lignes->pluck('matiere_id')->all()),
        ];
    }
}
