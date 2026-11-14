<?php

declare(strict_types=1);

namespace App\Services\KnowledgeCheck;

use App\Models\KnowledgeCheck;
use App\Models\KnowledgeCheckAttempt;
use App\Models\User;
use App\Services\Chapter\ChapterTimeRecorder;
use App\Services\Lesson\LessonProgressGate;
use Illuminate\Database\ConnectionInterface;
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
 * - **`startAttempt`** : valider le quota (`canAttempt`), OUVRIR la tentative
 *   avec sa présentation (le mélange conditionnel des questions ET des
 *   options, C44), masquer les bonnes réponses, retourner le payload
 *   « in progress ».
 * - **`submitAttempt`** : noter la remise dans l'ordre que l'élève a vu
 *   ({@see KnowledgeCheckOpenAttempts}), COMPLÉTER la tentative, mettre à
 *   jour `ChapterProgress` (best score + complétion + temps cumulé). Sauf pour
 *   le personnel (aperçu depuis l'écran de gestion des chapitres) : sa
 *   tentative est persistée, sa progression JAMAIS (#934, LessonProgressGate).
 * - **`getMyAttempts`** : historique des tentatives REMISES d'un user pour un
 *   quiz (une tentative ouverte n'a pas de note).
 *
 * ## DI strict (§1.6 D)
 *
 * Les collaborateurs sont injectés par constructeur — jamais `app()`.
 *
 * @see app/Http/Controllers/API/KnowledgeCheckAttemptController.php
 */
final class KnowledgeCheckAttemptService
{
    /**
     * Tentatives de la transaction de soumission (#944). Le temps du chapitre
     * s'y écrit par ChapterTimeRecorder, dont la propre transaction, imbriquée,
     * ne peut pas retenter : Laravel ne retente qu'au premier niveau. Un
     * interblocage résiduel (deux toutes premières écritures simultanées sur la
     * même leçon) doit donc être retenté ICI. Le rejeu est sûr : InnoDB annule
     * toute la transaction (tentative et progression), puis la fermeture repart
     * de zéro.
     */
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private readonly KnowledgeCheckOpenAttempts $openAttempts,
        private readonly KnowledgeCheckAccessService $access,
        private readonly ConnectionInterface $db,
        private readonly ChapterTimeRecorder $timeRecorder,
        private readonly LessonProgressGate $progressGate,
    ) {
    }

    /**
     * Démarre une tentative : vérifie le quota, ouvre la tentative avec sa
     * présentation (mélange conditionnel, C44), prépare la version « élève »
     * des questions (masquage des réponses correctes) et retourne le payload
     * destiné au frontend.
     *
     * Retourne `null` si l'utilisateur a atteint son quota de tentatives —
     * le controller traduit en réponse 400.
     *
     * @return array{
     *     attempt_id: int|string,
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

        // La présentation est écrite sur la tentative OUVERTE (C44) : la remise
        // sera notée dans l'ordre que l'élève voit.
        /** @var array{attempt: KnowledgeCheckAttempt, questions: list<array<string, mixed>>} $ouverte */
        $ouverte = $this->db->transaction(
            fn (): array => $this->openAttempts->ouvrir($quiz, $user),
            self::TRANSACTION_ATTEMPTS,
        );

        $questionsForStudent = array_map(static function (array $q): array {
            return [
                'question' => $q['question'] ?? null,
                'type' => $q['type'] ?? null,
                'options' => $q['options'] ?? [],
                'points' => $q['points'] ?? 1,
            ];
        }, $ouverte['questions']);

        return [
            'attempt_id' => $ouverte['attempt']->id,
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
     * progression du chapitre (best score + complétion + temps cumulé). Pour
     * le personnel, la tentative est persistée mais la progression jamais
     * écrite (#934) ; la réponse est la même.
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

        // Notée et complétée sous le verrou de la tentative ouverte (C44) : un
        // refus (409, 422) annule la transaction, et rien n'est écrit.
        /** @var array{attempt: KnowledgeCheckAttempt, result: array{correct_answers: int, total_questions: int, score: int, passed: bool, detailed_answers: array<int, array<string, mixed>>}, affiche: list<array<string, mixed>>} $remise */
        $remise = $this->db->transaction(function () use ($quiz, $user, $answers, $timeSpentSeconds): array {
            $ouverte = $this->openAttempts->verrouiller($quiz, $user);
            $notee = $this->openAttempts->noter($quiz, $ouverte, $answers);
            $attempt = $this->openAttempts->completer($ouverte, $quiz, $user, $notee['result'], $timeSpentSeconds);

            $this->updateChapterProgress(
                $user,
                (int) $quiz->chapter_id,
                $notee['result']['score'],
                $notee['result']['passed'],
                $timeSpentSeconds,
            );

            return ['attempt' => $attempt] + $notee;
        }, self::TRANSACTION_ATTEMPTS);
        $result = $remise['result'];

        return [
            'attempt_id' => $remise['attempt']->id,
            'score' => $result['score'],
            'correct_answers' => $result['correct_answers'],
            'total_questions' => $result['total_questions'],
            'passed' => $result['passed'],
            'passing_score' => (int) $quiz->passing_score,
            'time_spent' => $remise['attempt']->formatted_duration,
            // Dans l'ordre affiché : l'écran de correction le lit sur les questions reçues au démarrage.
            'answers' => $quiz->show_correct_answers ? $remise['affiche'] : null,
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
            ->completed()
            ->orderBy('created_at', 'desc')
            ->get();

        $attempts = $rows->map(self::ligneDHistorique(...));

        // Les seules tentatives remises (C44) : `score` n'y est jamais nul.
        $meilleur = $attempts->max('score');

        return [
            'quiz_title' => $quiz->title,
            'passing_score' => (int) $quiz->passing_score,
            'attempts' => $attempts,
            'best_score' => is_numeric($meilleur) ? (int) $meilleur : null,
            'has_passed' => $attempts->contains('passed', true),
        ];
    }

    /**
     * Une tentative remise, mise en forme pour l'historique. Le type annoncé est
     * celui de la signature de getMyAttempts : la collection n'est pas covariante
     * pour Larastan, une forme plus précise n'y serait pas admise.
     *
     * @return array<string, mixed>
     */
    private static function ligneDHistorique(KnowledgeCheckAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'score' => $attempt->score,
            'correct_answers' => $attempt->correct_answers,
            'total_questions' => $attempt->total_questions,
            'passed' => $attempt->passed,
            'time_spent' => $attempt->formatted_duration,
            'completed_at' => $attempt->completed_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * Met à jour la progression du chapitre après une soumission :
     *   - conserve le meilleur score (`quiz_score`),
     *   - marque comme réussi + complète si `passed`,
     *   - ajoute le temps passé, par son unique écrivain (#944).
     *
     * Le temps passe EN PREMIER : ChapterTimeRecorder verrouille la ligne de la
     * leçon avant celle du chapitre, et ce même ordre doit valoir pour le score
     * écrit ensuite dans la même transaction. Écrire le chapitre d'abord
     * croiserait les verrous d'un autre écrivain et ouvrirait un interblocage.
     *
     * Le score et la complétion restent ici, inchangés : leur divergence avec
     * ChapterProgressService::recordQuizScore est suivie par #955.
     */
    private function updateChapterProgress(
        User $user,
        int $chapterId,
        int $score,
        bool $passed,
        int $timeSpentSeconds
    ): void {
        if (! $this->progressGate->ecritLaProgression($user)) {
            // Aperçu du personnel : la tentative est persistée (l'écran enseignant
            // relit son meilleur score), la progression JAMAIS (#934).
            return;
        }

        $progress = $this->timeRecorder->record($user->id, $chapterId, max(0, $timeSpentSeconds));

        if ($progress->quiz_score === null || $score > $progress->quiz_score) {
            $progress->quiz_score = $score;
        }

        if ($passed) {
            $progress->quiz_passed = true;
            if (! $progress->completed_at) {
                $progress->completed_at = now();
            }
        }

        $progress->save();
    }
}
