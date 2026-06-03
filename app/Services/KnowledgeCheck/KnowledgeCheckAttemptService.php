<?php

declare(strict_types=1);

namespace App\Services\KnowledgeCheck;

use App\Models\ChapterProgress;
use App\Models\KnowledgeCheck;
use App\Models\KnowledgeCheckAttempt;
use App\Models\User;
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
 * - **`submitAttempt`** : déléguer au {@see KnowledgeCheckGradingService}
 *   pour le calcul du score, persister le `KnowledgeCheckAttempt`, mettre à
 *   jour `ChapterProgress` (best score + complétion + temps cumulé).
 * - **`getMyAttempts`** : historique des tentatives d'un user pour un quiz.
 *
 * ## DI strict (§1.6 D)
 *
 * Le grader est injecté par constructeur — jamais `app()`.
 *
 * @see app/Http/Controllers/API/KnowledgeCheckAttemptController.php
 */
final class KnowledgeCheckAttemptService
{
    public function __construct(
        private readonly KnowledgeCheckGradingService $grader,
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

        if (! $quiz->canAttempt($user->id)) {
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
     * Soumet les réponses : grade, persiste l'attempt, met à jour la
     * progression du chapitre (best score + complétion + temps cumulé).
     *
     * Retourne le payload destiné au frontend (déjà mis en forme).
     *
     * @param  array<int|string, mixed>  $answers
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
    public function submitAttempt(
        string $quizId,
        User $user,
        array $answers,
        int $timeSpentSeconds
    ): array {
        $quiz = KnowledgeCheck::findOrFail($quizId);

        $result = $this->grader->gradeAttempt($quiz, $answers);

        $attempt = KnowledgeCheckAttempt::create([
            'knowledge_check_id' => $quiz->id,
            'user_id' => $user->id,
            'score' => $result['score'],
            'correct_answers' => $result['correct_answers'],
            'total_questions' => $result['total_questions'],
            'answers' => $result['detailed_answers'],
            'time_spent_seconds' => $timeSpentSeconds,
            'passed' => $result['passed'],
            'started_at' => now()->subSeconds($timeSpentSeconds),
            'completed_at' => now(),
        ]);

        $this->updateChapterProgress(
            $user->id,
            (int) $quiz->chapter_id,
            $result['score'],
            $result['passed'],
            $timeSpentSeconds,
        );

        return [
            'attempt_id' => $attempt->id,
            'score' => $result['score'],
            'correct_answers' => $result['correct_answers'],
            'total_questions' => $result['total_questions'],
            'passed' => $result['passed'],
            'passing_score' => (int) $quiz->passing_score,
            'time_spent' => $attempt->formatted_duration,
            'answers' => $quiz->show_correct_answers ? $result['detailed_answers'] : null,
            'can_retry' => $quiz->canAttempt($user->id),
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

    /**
     * Met à jour la progression du chapitre après une soumission :
     *   - conserve le meilleur score (`quiz_score`),
     *   - marque comme réussi + complète si `passed`,
     *   - accumule le temps passé.
     *
     * Logique extraite verbatim de l'ancien controller.
     */
    private function updateChapterProgress(
        int $userId,
        int $chapterId,
        int $score,
        bool $passed,
        int $timeSpentSeconds
    ): void {
        $progress = ChapterProgress::getOrCreate($userId, $chapterId);

        if ($progress->quiz_score === null || $score > $progress->quiz_score) {
            $progress->quiz_score = $score;
        }

        if ($passed) {
            $progress->quiz_passed = true;
            if (! $progress->completed_at) {
                $progress->completed_at = now();
            }
        }

        $progress->time_spent_seconds = ((int) $progress->time_spent_seconds) + $timeSpentSeconds;
        $progress->save();
    }
}
