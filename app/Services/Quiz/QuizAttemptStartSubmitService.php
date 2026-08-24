<?php

declare(strict_types=1);

namespace App\Services\Quiz;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\Attempts\AttemptConflictGuard;
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
        private readonly AttemptConflictGuard $conflictGuard,
    ) {
    }

    /**
     * Démarre une tentative — ou rend celle qui est déjà ouverte.
     *
     * Avant #581, `attemptsCountForUser()` ignorait les tentatives
     * `in_progress` et rien ne traitait la précédente : trois onglets ouvraient
     * trois tentatives sur un quiz à `max_attempts = 1`, toutes soumissibles,
     * et la meilleure des trois notes était retenue.
     *
     * La tentative en cours est désormais **reprise**, pas abandonnée : la
     * réabandonner repartirait d'un `started_at` neuf et donnerait un temps
     * illimité sur un quiz chronométré, ce qui viderait de son sens le quota
     * qu'on cherche à faire respecter.
     *
     * @return array{status:int,success:bool,message:?string,data:?array<string,mixed>,errors:?array<string,mixed>}
     */
    public function startAttempt(Quiz $quiz, User $user): array
    {
        if (! $this->access->isAvailable($quiz)) {
            return $this->failure(403, 'Ce quiz n\'est pas disponible actuellement');
        }

        $resumable = $this->access->activeAttemptForUser($quiz, $user->id);
        if ($resumable !== null) {
            return $this->attemptStarted($quiz, $resumable, resumed: true);
        }

        // `canOpenNewAttempt` et non `canUserAttempt` : l'absence de tentative
        // reprenable vient d'être établie, la relire serait une requête pour rien.
        if (! $this->access->canOpenNewAttempt($quiz, $user->id)) {
            return $this->failure(403, 'Vous avez atteint le nombre maximum de tentatives');
        }

        return $this->openNewAttempt($quiz, $user);
    }

    /**
     * Insère la tentative sous le filet d'unicité de la base.
     *
     * Le perdant d'une course relit la tentative gagnante : si elle est encore
     * ouverte il la reprend (double-clic → 200), sinon le conflit est signalé
     * en 409 — jamais en 500 comme avant #581.
     *
     * @return array{status:int,success:bool,message:?string,data:?array<string,mixed>,errors:?array<string,mixed>}
     */
    private function openNewAttempt(Quiz $quiz, User $user): array
    {
        $attemptNumber = $this->access->nextAttemptNumberForUser($quiz, $user->id);

        $outcome = $this->conflictGuard->insert(
            fn (): QuizAttempt => QuizAttempt::create([
                'quiz_id'        => $quiz->id,
                'user_id'        => $user->id,
                'attempt_number' => $attemptNumber,
                'status'         => 'in_progress',
                'started_at'     => now(),
                // Scope tenant explicite (défense en profondeur, fix E2E #211) :
                // un tenant non résolu persistait la tentative avec institution_id
                // NULL → owner verrouillé dehors par ShowAttemptRequest.
                'institution_id' => $user->institution_id,
            ]),
            fn (): ?QuizAttempt => $this->access->activeAttemptForUser($quiz, $user->id),
        );

        if ($outcome === null) {
            return $this->failure(
                409,
                'Une autre tentative vient d\'être enregistrée pour ce quiz. Rechargez la page.'
            );
        }

        return $this->attemptStarted($quiz, $outcome->attempt(), resumed: $outcome->isResolved());
    }

    /**
     * Payload commun aux deux issues de succès : seuls le code HTTP et le
     * message distinguent une création (201) d'une reprise (200).
     *
     * Le mélange s'applique aussi à une reprise — le contraire révélerait
     * l'ordre d'origine à qui recharge la page. `time_remaining` est calculé
     * depuis le `started_at` d'origine : c'est tout l'intérêt de reprendre.
     *
     * @return array{status:int,success:bool,message:?string,data:?array<string,mixed>,errors:?array<string,mixed>}
     */
    private function attemptStarted(Quiz $quiz, QuizAttempt $attempt, bool $resumed): array
    {
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
            'status'  => $resumed ? 200 : 201,
            'success' => true,
            'message' => $resumed ? 'Reprise de la tentative en cours' : 'Tentative démarrée',
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
