<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\User;

/**
 * École créée depuis le LMS : la liste de l'élève (D72, E2a).
 *
 * Les évaluations de ses classes actives ({@see LocalLearnerEvaluationAudience}),
 * dans la même forme que la source KLASSCI : la classe et la matière se lisent
 * sur l'évaluation (`classe_id`, `matiere_id` et leurs libellés, posés à la
 * création), la fenêtre est calculée sur la ligne ({@see LocalEvaluationWindow}).
 * Aucun appel à KLASSCI.
 */
final class LocalStudentEvaluationsReader implements StudentEvaluationsReader
{
    public function __construct(private readonly LocalLearnerEvaluationAudience $audience) {}

    /**
     * Jamais null : sans classe active, une liste vide.
     *
     * @return array<int, array<string, mixed>>
     */
    public function evaluationsFor(User $user): array
    {
        return $this->audience->visibleFor($user)
            ->withCount('questions')
            ->orderBy('date_evaluation', 'desc')
            ->get()
            ->map(fn (Evaluation $evaluation): array => $this->enrichie($evaluation, $user))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function enrichie(Evaluation $evaluation, User $eleve): array
    {
        return array_merge($evaluation->toArray(), [
            'classe' => ['id' => $evaluation->classe_id, 'nom' => $evaluation->classe_nom],
            'matiere' => ['id' => $evaluation->matiere_id, 'nom' => $evaluation->matiere_nom],
            'programmation' => [
                'date_evaluation' => $evaluation->date_evaluation?->toIso8601String(),
                'window' => LocalEvaluationWindow::of($evaluation),
            ],
            'questions_count' => is_numeric($nombre = $evaluation->getAttribute('questions_count')) ? (int) $nombre : 0,
            'student_submission' => $evaluation->submissions()->ownedBy($eleve)->latest()->first(),
        ]);
    }
}
