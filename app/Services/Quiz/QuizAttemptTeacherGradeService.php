<?php

declare(strict_types=1);

namespace App\Services\Quiz;

use App\Models\QuizAttempt;
use App\Models\User;

/**
 * Correction manuelle d'une tentative par un enseignant.
 *
 * Issu du split de `QuizAttemptLifecycleService` (372l) en 3 services SRP
 * conformes §1.1 (≤300l). Couvre l'unique opération d'écriture côté prof
 * (notation manuelle des questions à réponse ouverte).
 *
 * Lectures + state checks → {@see QuizAttemptStateService}.
 * Flow étudiant start/submit → {@see QuizAttemptStartSubmitService}.
 *
 * Contrat de retour normalisé :
 *     array{status:int, success:bool, message:?string, data:mixed, errors:?array}
 *
 * DI strict §1.6 D : constructor injection, aucun `app(...)`.
 *
 * @see app/Http/Controllers/API/Quiz/QuizAttemptTeacherController.php
 * @see app/Services/Quiz/QuizGradingService.php
 */
final class QuizAttemptTeacherGradeService
{
    public function __construct(private readonly QuizGradingService $grading)
    {
    }

    /**
     * Correction manuelle d'une tentative.
     *
     * @return array{status:int,success:bool,message:?string,data:?QuizAttempt,errors:?array<string,mixed>}
     */
    public function manualGrade(
        QuizAttempt $attempt,
        User $grader,
        float $pointsEarned,
        ?string $feedback,
    ): array {
        $this->grading->manualGradeAttempt($attempt, $pointsEarned, $grader->id, $feedback);

        return [
            'status'  => 200,
            'success' => true,
            'message' => 'Tentative notée avec succès',
            'data'    => $attempt->fresh(['user', 'grader']),
            'errors'  => null,
        ];
    }
}
