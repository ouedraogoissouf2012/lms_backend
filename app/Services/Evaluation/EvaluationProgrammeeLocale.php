<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\Matiere;
use App\Services\Evaluation\Student\LocalEvaluationWindow;
use App\Services\Evaluation\Student\ResultatsVisibles;
use App\Support\Catalogue\SourceLocale;

/**
 * La forme d'une évaluation LMS dans les listes des fiches classe et matière (D72,
 * E4c) : UNE forme, et non trois.
 *
 * - {@see noyau()} : l'évaluation « LMS seule » de la fiche matière d'une école
 *   KLASSCI, telle qu'elle était rendue (`MatiereEvaluationsFetcher`), inchangée.
 * - {@see locale()} : le même noyau pour une école créée depuis le LMS, complété de ce
 *   que le front lit et que le noyau n'a pas : l'id numérique (que la fiche classe
 *   ouvre), `duree_minutes` à plat, la fenêtre, la matière, et l'origine
 *   (`source: lms_local`, D172 ; ABSENTE côté KLASSCI).
 *
 * `has_online` et `online_version.id` sont toujours posés : sans eux, le front
 * enverrait un enseignant vers la création KLASSCI avec un id local sous
 * `klassci_evaluation_id` (D132).
 *
 * La copie de l'élève passe par la règle unique des résultats visibles (C25). Les
 * comptes `questions_count` et `submissions_count` sont chargés par l'appelant
 * (`withCount`), en une requête.
 */
final class EvaluationProgrammeeLocale
{
    public function __construct(private readonly EvaluationStateService $etat) {}

    /**
     * @return array<string, mixed>
     */
    public function noyau(Evaluation $evaluation, ?EvaluationSubmission $copie): array
    {
        return [
            'id' => 'lms_' . $evaluation->id,
            'lms_id' => $evaluation->id,
            'titre' => $evaluation->titre,
            'description' => $evaluation->description,
            'type' => 'lms_pure',
            'matiere' => null,
            'classe' => null,
            'programmation' => $this->programmation($evaluation),
            'has_online' => true,
            'online_version' => [
                'id' => $evaluation->id,
                'status' => $evaluation->status,
                'is_published' => $evaluation->is_published,
                'is_locked' => $this->etat->isLocked($evaluation),
                'can_be_edited' => $this->etat->canBeEdited($evaluation),
                'questions_count' => $evaluation->questions_count,
                'submissions_count' => $evaluation->submissions_count,
            ],
            // Sans sa note si les résultats ne sont pas visibles (C25).
            'student_submission' => ResultatsVisibles::masquer($copie, $evaluation),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function locale(Evaluation $evaluation, ?EvaluationSubmission $copie, ?Matiere $matiere): array
    {
        return array_merge($this->noyau($evaluation, $copie), [
            'id' => $evaluation->id,
            'duree_minutes' => $evaluation->duree_minutes,
            'matiere' => $matiere === null ? null : ['id' => $matiere->id, 'nom' => $matiere->libelle],
            'programmation' => $this->programmation($evaluation) + ['window' => LocalEvaluationWindow::of($evaluation)],
            'source' => SourceLocale::LMS_LOCAL,
        ]);
    }

    /**
     * @return array{date_evaluation: mixed, duree_minutes: mixed, coefficient: mixed, bareme: mixed}
     */
    private function programmation(Evaluation $evaluation): array
    {
        return [
            'date_evaluation' => $evaluation->date_evaluation,
            'duree_minutes' => $evaluation->duree_minutes,
            'coefficient' => $evaluation->coefficient,
            'bareme' => $evaluation->bareme,
        ];
    }
}
