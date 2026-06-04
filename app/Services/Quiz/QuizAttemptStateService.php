<?php

declare(strict_types=1);

namespace App\Services\Quiz;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\Quiz\Concerns\BuildsAttemptResponses;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Lectures + state checks sur une tentative de quiz.
 *
 * Issu du split de `QuizAttemptLifecycleService` (372l) en 3 services SRP
 * conformes §1.1 (≤300l). Couvre les opérations de lecture (`showAttempt`,
 * `getAttemptsForQuiz`) et de gestion d'état (`checkTimeRemaining`,
 * `saveProgress`, incluant l'auto-submit si le temps expire).
 *
 * Écriture étudiant (start/submit) → {@see QuizAttemptStartSubmitService}.
 * Correction manuelle prof → {@see QuizAttemptTeacherGradeService}.
 *
 * Contrat de retour normalisé (sauf `getAttemptsForQuiz` qui retourne un
 * paginator brut pour le controller enseignant) :
 *     array{status:int, success:bool, message:?string, data:mixed, errors:?array}
 *
 * DI strict §1.6 D : constructor injection, aucun `app(...)`.
 *
 * @see app/Http/Controllers/API/Quiz/QuizAttemptStudentController.php
 * @see app/Http/Controllers/API/Quiz/QuizAttemptTeacherController.php
 */
final class QuizAttemptStateService
{
    use BuildsAttemptResponses;

    public function __construct(private readonly QuizGradingService $grading)
    {
    }

    /**
     * Détails d'une tentative.
     *
     * @return array{status:int,success:bool,message:?string,data:QuizAttempt,errors:?array<string,mixed>}
     */
    public function showAttempt(QuizAttempt $attempt, User $user): array
    {
        $quiz        = $attempt->quiz;
        $allowReview = $quiz !== null && (bool) $quiz->allow_review;

        if (
            $attempt->status !== 'in_progress'
            && ($allowReview || $user->isTeacher() || $user->isAdmin())
        ) {
            $attempt->questions_with_results = $this->buildQuestionsWithResults($attempt, $this->grading);
        }

        $attempt->time_spent_formatted = $attempt->getFormattedTimeSpent();
        $attempt->time_remaining       = $attempt->getTimeRemaining();

        return [
            'status'  => 200,
            'success' => true,
            'message' => null,
            'data'    => $attempt,
            'errors'  => null,
        ];
    }

    /**
     * Liste paginée des tentatives d'un quiz (enseignants).
     *
     * @param  array{user_id?:int|string,per_page?:int|string}  $filters
     */
    public function getAttemptsForQuiz(Quiz $quiz, array $filters): LengthAwarePaginator
    {
        $query = $quiz->attempts()->with('user:id,name,email')->submitted();

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        $query->orderBy('submitted_at', 'desc');

        $attempts = $query->paginate((int) ($filters['per_page'] ?? 20));

        $attempts->getCollection()->transform(function ($attempt) {
            $attempt->time_spent_formatted = $attempt->getFormattedTimeSpent();
            return $attempt;
        });

        return $attempts;
    }

    /**
     * Temps restant + auto-submit si expiré.
     *
     * @return array{status:int,success:bool,message:?string,data:?array<string,mixed>,errors:?array<string,mixed>}
     */
    public function checkTimeRemaining(QuizAttempt $attempt, User $user): array
    {
        if ($attempt->user_id !== $user->id) {
            return $this->failure(403, 'Vous n\'êtes pas autorisé');
        }

        if ($attempt->status !== 'in_progress') {
            return [
                'status'  => 200,
                'success' => true,
                'message' => null,
                'data'    => [
                    'time_remaining_seconds' => 0,
                    'is_expired'             => true,
                    'status'                 => $attempt->status,
                ],
                'errors'  => null,
            ];
        }

        $timeRemaining = $attempt->getTimeRemaining();

        if ($attempt->isTimeExpired()) {
            // Auto-submit via grading service (plus de `QuizAttempt::submit()`
            // qui appelait `app(...)` — DI strict).
            $this->grading->submitAttempt($attempt, $attempt->answers ?? []);

            return [
                'status'  => 422,
                'success' => false,
                'message' => 'Le temps est écoulé. Votre tentative a été soumise automatiquement.',
                'data'    => [
                    'time_remaining_seconds' => 0,
                    'is_expired'             => true,
                    'auto_submitted'         => true,
                    'attempt'                => $attempt->fresh(),
                ],
                'errors'  => null,
            ];
        }

        $quiz = $attempt->quiz;

        return [
            'status'  => 200,
            'success' => true,
            'message' => null,
            'data'    => [
                'time_remaining_seconds' => $timeRemaining,
                'is_expired'             => false,
                'started_at'             => $attempt->started_at,
                'duration_minutes'       => $quiz?->duration_minutes,
            ],
            'errors'  => null,
        ];
    }

    /**
     * Sauvegarde temporaire des réponses (auto-submit si expiré).
     *
     * @param  array<int|string,mixed>  $answers
     * @return array{status:int,success:bool,message:?string,data:?array<string,mixed>,errors:?array<string,mixed>}
     */
    public function saveProgress(QuizAttempt $attempt, User $user, array $answers): array
    {
        if ($attempt->user_id !== $user->id) {
            return $this->failure(403, 'Vous n\'êtes pas autorisé');
        }

        if ($attempt->status !== 'in_progress') {
            return $this->failure(422, 'Cette tentative ne peut plus être modifiée');
        }

        if ($attempt->isTimeExpired()) {
            $this->grading->submitAttempt($attempt, $answers);

            return [
                'status'  => 422,
                'success' => false,
                'message' => 'Le temps est écoulé. Votre tentative a été soumise automatiquement.',
                'data'    => [
                    'time_expired' => true,
                    'attempt'      => $attempt->fresh(),
                ],
                'errors'  => null,
            ];
        }

        $attempt->answers = $answers;
        $attempt->save();

        return [
            'status'  => 200,
            'success' => true,
            'message' => 'Progression sauvegardée',
            'data'    => [
                'time_remaining' => $attempt->getTimeRemaining(),
                'saved_at'       => Carbon::now(),
            ],
            'errors'  => null,
        ];
    }
}
