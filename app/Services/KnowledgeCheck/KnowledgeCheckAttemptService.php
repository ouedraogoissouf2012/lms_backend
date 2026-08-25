<?php

declare(strict_types=1);

namespace App\Services\KnowledgeCheck;

use App\Models\KnowledgeCheck;
use App\Models\KnowledgeCheckAttempt;
use App\Models\User;
use App\Services\Attempts\AttemptConflictGuard;
use App\Services\Chapter\ChapterQuizProgressUpdater;
use Illuminate\Database\Eloquent\Collection;

/**
 * Service de la « state machine » des tentatives étudiant pour les quiz
 * « Testez vos connaissances ».
 *
 * Extrait verbatim de `KnowledgeCheckController` (méthodes `startAttempt`,
 * `submitAttempt`, `myAttempts`).
 *
 * ## Responsabilités
 *
 * - **`startAttempt`** : valider le quota (`canAttempt`), préparer les
 *   questions (shuffle conditionnel des questions ET des options), masquer
 *   les bonnes réponses, retourner le payload « in progress ».
 * - **`submitAttempt`** : appliquer le quota, déléguer au
 *   {@see KnowledgeCheckGradingService} pour le calcul du score, persister le
 *   `KnowledgeCheckAttempt` sous le filet d'unicité de la base
 *   ({@see \App\Services\Attempts\AttemptConflictGuard}), puis reporter la
 *   progression via {@see \App\Services\Chapter\ChapterQuizProgressUpdater}.
 * - **`getMyAttempts`** : historique des tentatives d'un user pour un quiz.
 *
 * ## DI strict (§1.6 D)
 *
 * Tous les collaborateurs sont injectés par constructeur — jamais `app()`.
 *
 * @see app/Http/Controllers/API/KnowledgeCheckAttemptController.php
 */
final class KnowledgeCheckAttemptService
{
    public function __construct(
        private readonly KnowledgeCheckGradingService $grader,
        private readonly KnowledgeCheckAccessService $access,
        private readonly AttemptConflictGuard $conflictGuard,
        private readonly ChapterQuizProgressUpdater $chapterProgress,
    ) {
    }

    /**
     * Démarre une tentative : vérifie le quota, prépare la version « élève »
     * des questions (shuffle + masquage des réponses correctes) et retourne
     * le payload destiné au frontend.
     *
     * Retourne `null` si l'utilisateur a atteint son quota de tentatives —
     * le controller traduit en réponse 400.
     *
     * @return array{
     *     quiz_id: int|string,
     *     title: string,
     *     description: ?string,
     *     questions: array<int, array<string, mixed>>,
     *     time_limit_minutes: ?int,
     *     started_at: string
     * }|null
     */
    public function startAttempt(string $quizId, User $user): ?array
    {
        $quiz = KnowledgeCheck::findOrFail($quizId);

        if (! $this->access->canAttempt($quiz, $user->id)) {
            return null;
        }

        /** @var array<int, array<string, mixed>> $questions */
        $questions = $quiz->questions ?? [];

        if ($quiz->shuffle_questions) {
            shuffle($questions);
        }

        if ($quiz->shuffle_options) {
            foreach ($questions as &$q) {
                if (isset($q['options']) && is_array($q['options'])) {
                    shuffle($q['options']);
                }
            }
            unset($q);
        }

        $questionsForStudent = array_map(static function (array $q): array {
            return [
                'question' => $q['question'] ?? null,
                'type' => $q['type'] ?? null,
                'options' => $q['options'] ?? [],
                'points' => $q['points'] ?? 1,
            ];
        }, $questions);

        return [
            'quiz_id' => $quiz->id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'questions' => $questionsForStudent,
            'time_limit_minutes' => $quiz->time_limit_minutes,
            'started_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Soumet les reponses : applique le quota, grade, persiste l'attempt sous le
     * filet d'unicite de la base, puis reporte la progression du chapitre.
     *
     * Le quota est verifie ICI et non au seul `startAttempt` : `startAttempt` ne
     * persiste rien, donc un client pouvait appeler `/submit` en boucle et
     * depasser `max_attempts` sans provoquer la moindre concurrence (#540).
     *
     * Statuts retournes - le controller les traduit en codes HTTP :
     *   - `ok`           tentative enregistree (200)
     *   - `max_attempts` quota atteint, rien n'est persiste (400)
     *   - `conflict`     course concurrente perdue sur l'unique (409, jamais 500)
     *
     * @param  array<int|string, mixed>  $answers
     * @return array{status: string, data?: array{
     *     attempt_id: int|string,
     *     score: int,
     *     correct_answers: int,
     *     total_questions: int,
     *     passed: bool,
     *     passing_score: int,
     *     time_spent: string,
     *     answers: array<int, array<string, mixed>>|null,
     *     can_retry: bool,
     *     message: string
     * }}
     */
    public function submitAttempt(
        string $quizId,
        User $user,
        array $answers,
        int $timeSpentSeconds
    ): array {
        $quiz = KnowledgeCheck::findOrFail($quizId);

        if (! $this->access->canAttempt($quiz, $user->id)) {
            return ['status' => 'max_attempts'];
        }

        $result = $this->grader->gradeAttempt($quiz, $answers);
        $attemptNumber = $this->access->nextAttemptNumberForUser($quiz, $user->id);

        // Pas de `resolveWinner` : contrairement au demarrage d'une evaluation,
        // une soumission n'a pas d'equivalent << reprendre la gagnante >>, les
        // reponses du perdant n'etant pas celles du gagnant. Le conflit est donc
        // signale tel quel au client (409).
        $outcome = $this->conflictGuard->insert(
            fn (): KnowledgeCheckAttempt => KnowledgeCheckAttempt::create([
                'knowledge_check_id' => $quiz->id,
                'user_id' => $user->id,
                'attempt_number' => $attemptNumber,
                'score' => $result['score'],
                'correct_answers' => $result['correct_answers'],
                'total_questions' => $result['total_questions'],
                'answers' => $result['detailed_answers'],
                'time_spent_seconds' => $timeSpentSeconds,
                'passed' => $result['passed'],
                'started_at' => now()->subSeconds($timeSpentSeconds),
                'completed_at' => now(),
                // Scope tenant explicite (fix E2E #211 flow 2).
                'institution_id' => $user->institution_id,
            ]),
        );

        if ($outcome === null) {
            return ['status' => 'conflict'];
        }
        $attempt = $outcome->attempt();

        $this->chapterProgress->recordAttempt(
            $user->id,
            (int) $quiz->chapter_id,
            $result['score'],
            $result['passed'],
            $timeSpentSeconds,
        );

        return ['status' => 'ok', 'data' => $this->buildSubmissionPayload($quiz, $user, $attempt, $result)];
    }

    /**
     * Met en forme la reponse d'une soumission acceptee.
     *
     * @param  array{score: int, correct_answers: int, total_questions: int, passed: bool, detailed_answers: array<int, array<string, mixed>>}  $result
     * @return array{
     *     attempt_id: int|string,
     *     score: int,
     *     correct_answers: int,
     *     total_questions: int,
     *     passed: bool,
     *     passing_score: int,
     *     time_spent: string,
     *     answers: array<int, array<string, mixed>>|null,
     *     can_retry: bool,
     *     message: string
     * }
     */
    private function buildSubmissionPayload(
        KnowledgeCheck $quiz,
        User $user,
        KnowledgeCheckAttempt $attempt,
        array $result
    ): array {
        return [
            'attempt_id' => $attempt->id,
            'score' => $result['score'],
            'correct_answers' => $result['correct_answers'],
            'total_questions' => $result['total_questions'],
            'passed' => $result['passed'],
            'passing_score' => (int) $quiz->passing_score,
            'time_spent' => $attempt->formatted_duration,
            'answers' => $quiz->show_correct_answers ? $result['detailed_answers'] : null,
            'can_retry' => $this->access->canAttempt($quiz, $user->id),
            'message' => $result['passed']
                ? 'Felicitations! Vous avez reussi!'
                : 'Quiz termine. Continuez a vous entrainer!',
        ];
    }

    /**
     * Historique des tentatives d'un user sur un quiz, mis en forme pour
     * l'affichage (avec `best_score` et `has_passed` agrégés).
     *
     * @return array{
     *     quiz_title: string,
     *     passing_score: int,
     *     attempts: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *     best_score: int|null,
     *     has_passed: bool
     * }
     */
    public function getMyAttempts(string $quizId, User $user): array
    {
        $quiz = KnowledgeCheck::findOrFail($quizId);

        /** @var Collection<int, KnowledgeCheckAttempt> $rows */
        $rows = KnowledgeCheckAttempt::where('knowledge_check_id', $quizId)
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        $attempts = $rows->map(static function (KnowledgeCheckAttempt $attempt): array {
            return [
                'id' => $attempt->id,
                'score' => $attempt->score,
                'correct_answers' => $attempt->correct_answers,
                'total_questions' => $attempt->total_questions,
                'passed' => $attempt->passed,
                'time_spent' => $attempt->formatted_duration,
                'completed_at' => $attempt->completed_at?->format('d/m/Y H:i'),
            ];
        });

        return [
            'quiz_title' => $quiz->title,
            'passing_score' => (int) $quiz->passing_score,
            'attempts' => $attempts,
            'best_score' => $attempts->max('score'),
            'has_passed' => $attempts->contains('passed', true),
        ];
    }
}
