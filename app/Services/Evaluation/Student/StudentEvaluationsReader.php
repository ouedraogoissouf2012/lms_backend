<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\User;

/**
 * La liste de l'élève (`GET /evaluations/student`), selon le type de son école
 * (D72, E2a).
 *
 * Chaque élément est l'évaluation enrichie de `classe`, `matiere`,
 * `programmation.window`, `questions_count` et de la dernière copie de l'élève
 * (`student_submission`). Le front lit la fenêtre dans
 * `programmation.window.{has_started, is_open, has_ended}`.
 *
 * Liée par la fabrique (`EvaluationServiceProvider`), reçue par la MÉTHODE du
 * contrôleur (ADR-EI-01, règle 3).
 */
interface StudentEvaluationsReader
{
    /**
     * @return array<int, array<string, mixed>>|null  null : classe introuvable
     *                                                (école créée depuis KLASSCI).
     */
    public function evaluationsFor(User $user): ?array;
}
