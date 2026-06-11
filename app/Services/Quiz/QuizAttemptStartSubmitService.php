<?php

declare(strict_types=1);

namespace App\Services\Quiz;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\Quiz\Concerns\BuildsAttemptResponses;

/**
 * Flow étudiant : démarrer + soumettre une tentative de quiz.
 *
 * Issu du split de `QuizAttemptLifecycleService` (372l) en 3 services SRP
 * conformes §1.1 (≤300l). Couvre les deux opérations d'écriture du parcours
 * étudiant ; lecture/état → {@see QuizAttemptStateService} ; correction
 * manuelle → {@see QuizAttemptTeacherGradeService}.
 *
 * Contrat de retour normalisé (chaque méthode pub) :
 *     array{status:int, success:bool, message:?string, data:mixed, errors:?array}
 *
 * DI strict §1.6 D : constructor injection, aucun `app(...)`.
 *
 * @see app/Http/Controllers/API/Quiz/QuizAttemptStudentController.php
 * @see app/Services/Quiz/QuizGradingService.php
 */
final class QuizAttemptStartSubmitService
{
    use BuildsAttemptResponses;

    public function __construct(
        private readonly QuizGradingService $grading,
        private readonly QuizAccessService $access,
        private readonly QuizAttemptTimerService $timer,
    ) {
    }

    /**
     * Démarre une nouvelle tentative.
     *
     * @return array{status:int,success:bool,message:?string,data:?array<string,mixed>,errors:?array<string,mixed>}
     */
    public function startAttempt(Quiz $quiz, User $user): array
    {
        if (! $this->access->isAvailable($quiz)) {
            return $this->failure(403, 'Ce quiz n\'est pas disponible actuellement');
        }

        if (! $this->access->canUserAttempt($quiz, $user->id)) {
            return $this->failure(403, 'Vous avez atteint le nombre maximum de tentatives');
        }

        $attemptNumber = $this->access->nextAttemptNumberForUser($quiz, $user->id);

        $attempt = QuizAttempt::create([
            'quiz_id'        => $quiz->id,
            'user_id'        => $user->id,
            'attempt_number' => $attemptNumber,
            'status'         => 'in_progress',
            'started_at'     => now(),
            // Scope tenant explicite (défense en profondeur, fix E2E #211) :
            // un tenant non résolu persistait la tentative avec institution_id
            // NULL → owner verrouillé dehors par ShowAttemptRequest.
            'institution_id' => $user->institution_id,
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
                'time_remaining' => $this->timer->timeRemaining($attempt),
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

        if ($this->timer->hasExpired($attempt)) {
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
            'time_spent'      => $this->timer->formattedTimeSpent($attempt),
        ];

        $quiz = $attempt->quiz;
        if ($quiz !== null && $quiz->show_correct_answers && $attempt->status === 'graded') {
            $result['questions_with_results'] = $this->buildQuestionsWithResults($attempt, $this->grading);
        }

        return [
            'status'  => 200,
            'success' => true,
            'message' => 'Tentative soumise avec succès',
            'data'    => $result,
            'errors'  => null,
        ];
    }
}
