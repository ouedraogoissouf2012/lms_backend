<?php

declare(strict_types=1);

namespace App\Services\Quiz;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Orchestrateur métier des tentatives quiz (split-10/quiz-attempt).
 *
 * Extrait du god-controller `QuizAttemptController` (444l) — validations,
 * chargements et délégation au grading vivent ici ; les controllers
 * deviennent des thin shells conformes §5 (≤200l) et §1.1 (services ≤300l).
 *
 * Contrat de retour normalisé (chaque méthode pub) :
 *     array{status:int, success:bool, message:?string, data:mixed, errors:?array}
 *
 * DI strict §1.6 D : constructor injection, aucun `app(...)`.
 *
 * @see app/Http/Controllers/API/Quiz/QuizAttemptStudentController.php
 * @see app/Http/Controllers/API/Quiz/QuizAttemptTeacherController.php
 * @see app/Services/Quiz/QuizGradingService.php
 */
final class QuizAttemptLifecycleService
{
    public function __construct(private readonly QuizGradingService $grading)
    {
    }

    /**
     * Démarre une nouvelle tentative.
     *
     * @return array{status:int,success:bool,message:?string,data:?array<string,mixed>,errors:?array<string,mixed>}
     */
    public function startAttempt(Quiz $quiz, User $user): array
    {
        if (! $quiz->isAvailable()) {
            return $this->failure(403, 'Ce quiz n\'est pas disponible actuellement');
        }

        if (! $quiz->canUserAttempt($user->id)) {
            return $this->failure(403, 'Vous avez atteint le nombre maximum de tentatives');
        }

        $attemptNumber = $quiz->getAttemptsCountForUser($user->id) + 1;

        $attempt = QuizAttempt::create([
            'quiz_id'        => $quiz->id,
            'user_id'        => $user->id,
            'attempt_number' => $attemptNumber,
            'status'         => 'in_progress',
            'started_at'     => now(),
        ]);

        $questions = $quiz->questions()->with(['answers' => function ($query): void {
            $query->select('id', 'question_id', 'answer_text', 'order')->ordered();
        }])->ordered()->get();

        if ($quiz->shuffle_questions) {
            $questions = $questions->shuffle();
        }

        if ($quiz->shuffle_answers) {
            $questions->each(function ($question): void {
                $question->setRelation('answers', $question->answers->shuffle());
            });
        }

        return [
            'status'  => 201,
            'success' => true,
            'message' => 'Tentative démarrée',
            'data'    => [
                'attempt'        => $attempt,
                'quiz'           => $quiz,
                'questions'      => $questions,
                'time_remaining' => $attempt->getTimeRemaining(),
            ],
            'errors'  => null,
        ];
    }

    /**
     * Soumet une tentative : valide owner+status+expiration puis délègue au
     * grading service.
     *
     * @param  array<int|string,mixed>  $answers
     * @return array{status:int,success:bool,message:?string,data:?array<string,mixed>,errors:?array<string,mixed>}
     */
    public function submitAttempt(QuizAttempt $attempt, User $user, array $answers): array
    {
        if ($attempt->user_id !== $user->id) {
            return $this->failure(403, 'Vous n\'êtes pas autorisé');
        }

        if ($attempt->status !== 'in_progress') {
            return $this->failure(422, 'Cette tentative a déjà été soumise');
        }

        if ($attempt->isTimeExpired()) {
            $this->grading->submitAttempt($attempt, $answers);

            return [
                'status'  => 422,
                'success' => false,
                'message' => 'Le temps est écoulé. Votre tentative a été soumise automatiquement.',
                'data'    => [
                    'attempt'      => $attempt,
                    'time_expired' => true,
                ],
                'errors'  => null,
            ];
        }

        $this->grading->submitAttempt($attempt, $answers);
        $attempt->load(['quiz.questions.answers', 'user:id,name,email']);

        $result = [
            'attempt'         => $attempt,
            'score'           => $attempt->score,
            'points_earned'   => $attempt->points_earned,
            'points_possible' => $attempt->points_possible,
            'passed'          => $attempt->passed,
            'time_spent'      => $attempt->getFormattedTimeSpent(),
        ];

        $quiz = $attempt->quiz;
        if ($quiz !== null && $quiz->show_correct_answers && $attempt->status === 'graded') {
            $result['questions_with_results'] = $this->buildQuestionsWithResults($attempt);
        }

        return [
            'status'  => 200,
            'success' => true,
            'message' => 'Tentative soumise avec succès',
            'data'    => $result,
            'errors'  => null,
        ];
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
            $attempt->questions_with_results = $this->buildQuestionsWithResults($attempt);
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
     * Correction manuelle d'une tentative.
     *
     * @return array{status:int,success:bool,message:?string,data:?QuizAttempt,errors:?array<string,mixed>}
     */
    public function manualGrade(
        QuizAttempt $attempt,
        User $grader,
        float $pointsEarned,
        ?string $feedback
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

    /**
     * @return array{status:int,success:bool,message:?string,data:null,errors:?array<string,mixed>}
     */
    private function failure(int $status, string $message): array
    {
        return [
            'status'  => $status,
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => null,
        ];
    }

    /**
     * Helper extrait du god-controller (#176).
     *
     * @return array<int,array{question:mixed,user_answer:mixed,is_correct:?bool,points_earned:float,correct_answers:mixed}>
     */
    private function buildQuestionsWithResults(QuizAttempt $attempt): array
    {
        $quiz = $attempt->quiz;
        if ($quiz === null) {
            return [];
        }

        $questions   = $quiz->questions()->with('answers')->ordered()->get();
        $userAnswers = $attempt->answers ?? [];
        $grading     = $this->grading;

        return $questions->map(function ($question) use ($userAnswers, $grading): array {
            $userAnswer = $userAnswers[$question->id] ?? null;

            return [
                'question'        => $question,
                'user_answer'     => $userAnswer,
                'is_correct'      => $grading->checkAnswer($question, $userAnswer),
                'points_earned'   => $grading->calculatePoints($question, $userAnswer),
                'correct_answers' => $question->getCorrectAnswers(),
            ];
        })->toArray();
    }
}
