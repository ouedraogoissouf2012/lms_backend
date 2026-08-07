<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\LessonStatus;
use App\Models\ForumTopic;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Agrégation du dashboard enseignant (issue #364).
 *
 * Le bloc quiz utilise les colonnes réellement migrées (#401) :
 * `quizzes.created_by`, `quiz_attempts.score`, statuts `submitted|graded`,
 * et questions `short_answer|essay` pour détecter la correction manuelle.
 *
 * ## SRP / DI (§1.6)
 *
 * Une seule responsabilité : produire le payload du dashboard enseignant
 * pour un `User`. Aucune dépendance à injecter — requêtes via les modèles
 * Eloquent, cohérent avec {@see StudentDashboardService}.
 *
 * @see app/Http/Controllers/API/Dashboard/DashboardTeacherController.php
 */
final class TeacherDashboardService
{
    /**
     * Nombre maximum d'éléments des listes "à traiter" (quiz, topics).
     */
    private const LIST_LIMIT = 10;

    /**
     * Nombre de cours retournés dans le top engagement.
     */
    private const TOP_LESSONS_LIMIT = 5;

    /**
     * Construire le payload complet du dashboard enseignant.
     *
     * @return array<string, mixed>
     */
    public function buildDashboard(User $user): array
    {
        return [
            'lessons' => [
                'total' => Lesson::where('enseignant_id', $user->id)->count(),
                'published' => Lesson::where('enseignant_id', $user->id)
                    ->where('status', LessonStatus::Published->value)
                    ->count(),
                'draft' => Lesson::where('enseignant_id', $user->id)
                    ->where('status', LessonStatus::Draft->value)
                    ->count(),
                'top_lessons' => $this->topLessons($user),
            ],
            'students' => [
                'active_last_7_days' => $this->activeStudentsCount($user),
            ],
            'quizzes' => [
                'total' => Quiz::where('created_by', $user->id)->count(),
                'published' => Quiz::where('created_by', $user->id)
                    ->where('status', 'published')
                    ->count(),
                'to_grade' => $this->quizzesToGradeQuery($user)->count(),
                'to_grade_list' => $this->quizzesToGradeList($user),
                'total_attempts' => $this->completedAttemptsQuery($user)->count(),
                'average_score' => round((float) ($this->completedAttemptsQuery($user)->avg('score') ?? 0), 1),
            ],
            'forum' => [
                'unresolved_topics' => $this->unresolvedTopicsQuery($user)->count(),
                'unresolved_topics_list' => $this->unresolvedTopicsList($user),
            ],
        ];
    }

    /**
     * Cours publiés avec le plus d'engagement.
     *
     * PERF-03 — agrégats `withCount`/`withAvg` en une passe SQL (4 requêtes
     * au total, indépendant du nombre de cours).
     *
     * @return Collection<int, array{lesson_id: int, title: string, students_started: mixed, students_completed: mixed, average_progress: float}>
     */
    private function topLessons(User $user): Collection
    {
        return Lesson::query()
            ->where('enseignant_id', $user->id)
            ->where('status', LessonStatus::Published->value)
            ->withCount(['progress as students_started' => function ($query) {
                $query->whereIn('status', ['in_progress', 'completed']);
            }])
            ->withCount(['progress as students_completed' => function ($query) {
                $query->where('status', 'completed');
            }])
            ->withAvg('progress as average_progress', 'progress_percentage')
            ->orderBy('students_started', 'desc')
            ->limit(self::TOP_LESSONS_LIMIT)
            ->get()
            // Alias withCount/withAvg lus via getAttribute() : ils ne sont
            // pas des propriétés déclarées du modèle (Larastan L9).
            ->map(
                /** @return array<string, mixed> */
                function (Lesson $lesson): array {
                    return [
                        'lesson_id' => $lesson->id,
                        'title' => $lesson->title,
                        'students_started' => $lesson->getAttribute('students_started'),
                        'students_completed' => $lesson->getAttribute('students_completed') ?? 0,
                        'average_progress' => $this->roundedProgress($lesson->getAttribute('average_progress')),
                    ];
                }
            );
    }

    /**
     * Arrondi défensif de l'agrégat `withAvg` (mixed au niveau statique,
     * numérique ou null au runtime) — identique au `(float) ($x ?? 0)`
     * du controller d'origine.
     */
    private function roundedProgress(mixed $averageProgress): float
    {
        return round(is_numeric($averageProgress) ? (float) $averageProgress : 0.0, 1);
    }

    /**
     * Étudiants distincts ayant accédé à un cours de l'enseignant
     * dans les 7 derniers jours.
     */
    private function activeStudentsCount(User $user): int
    {
        return LessonProgress::query()
            ->whereHas('lesson', function ($query) use ($user) {
                // @phpstan-ignore argument.type (colonne réelle de lessons — relation non génériquée dans le modèle legacy, hors périmètre #364)
                $query->where('enseignant_id', $user->id);
            })
            ->where('last_accessed_at', '>=', now()->subDays(7))
            ->distinct('user_id')
            ->count('user_id');
    }

    /**
     * Base commune liste/compteur : tentatives complétées en attente de
     * correction manuelle sur les quiz de l'enseignant.
     *
     * @return Builder<QuizAttempt>
     */
    private function quizzesToGradeQuery(User $user): Builder
    {
        return QuizAttempt::query()
            ->whereIn('quiz_id', $this->manualGradingQuizIds($user))
            ->where('status', 'submitted')
            ->whereNull('graded_at')
            ->whereNull('score');
    }

    /**
     * Les 10 dernières tentatives à corriger, projetées pour l'affichage.
     *
     * @return Collection<int, array{attempt_id: int, quiz_title: string|null, student_name: mixed, completed_at: Carbon|string|null, auto_score: numeric-string|null}>
     */
    private function quizzesToGradeList(User $user): Collection
    {
        return $this->quizzesToGradeQuery($user)
            ->with(['quiz', 'user'])
            ->orderBy('submitted_at', 'desc')
            ->limit(self::LIST_LIMIT)
            ->get()
            // `?->` + getAttribute() : relations non génériquées dans les
            // modèles legacy (hors périmètre) — FK non-nulles en pratique,
            // projection identique au controller d'origine.
            ->map(
                /** @return array<string, mixed> */
                function (QuizAttempt $attempt): array {
                    return [
                        'attempt_id' => $attempt->id,
                        'quiz_title' => $attempt->quiz?->title,
                        'student_name' => $attempt->user?->getAttribute('name'),
                        'completed_at' => $attempt->completed_at ?? $attempt->submitted_at,
                        'auto_score' => $attempt->score,
                    ];
                }
            );
    }

    /**
     * Base commune compteur/moyenne : tentatives complétées sur les quiz
     * de l'enseignant.
     *
     * @return Builder<QuizAttempt>
     */
    private function completedAttemptsQuery(User $user): Builder
    {
        return QuizAttempt::query()
            ->whereIn('quiz_id', Quiz::query()->where('created_by', $user->id)->select('id'))
            ->whereIn('status', ['submitted', 'graded']);
    }

    /**
     * @return Builder<Quiz>
     */
    private function manualGradingQuizIds(User $user): Builder
    {
        $manualQuestionQuizIds = QuizQuestion::query()
            ->whereIn('type', ['short_answer', 'essay'])
            ->select('quiz_id');

        return Quiz::query()
            ->where('created_by', $user->id)
            ->whereIn('id', $manualQuestionQuizIds)
            ->select('id');
    }

    /**
     * Base commune liste/compteur : topics ouverts non résolus dans les
     * cours de l'enseignant.
     *
     * @return Builder<ForumTopic>
     */
    private function unresolvedTopicsQuery(User $user): Builder
    {
        return ForumTopic::query()
            ->whereHas('lesson', function ($query) use ($user) {
                // @phpstan-ignore argument.type (colonne réelle de lessons — relation non génériquée dans le modèle legacy, hors périmètre #364)
                $query->where('enseignant_id', $user->id);
            })
            ->where('is_resolved', false)
            ->where('status', 'open');
    }

    /**
     * Les 10 derniers topics non résolus, projetés pour l'affichage.
     *
     * @return Collection<int, array{topic_id: int, title: string, lesson_title: mixed, author: mixed, posts_count: int, views_count: int, created_at: Carbon|null}>
     */
    private function unresolvedTopicsList(User $user): Collection
    {
        return $this->unresolvedTopicsQuery($user)
            ->with(['user', 'lesson'])
            ->orderBy('created_at', 'desc')
            ->limit(self::LIST_LIMIT)
            ->get()
            // `?->getAttribute()` : relation user non génériquée dans le
            // modèle legacy (hors périmètre) — projection identique au
            // controller d'origine.
            ->map(
                /** @return array<string, mixed> */
                function (ForumTopic $topic): array {
                    return [
                        'topic_id' => $topic->id,
                        'title' => $topic->title,
                        'lesson_title' => $topic->lesson?->getAttribute('title'),
                        'author' => $topic->user?->getAttribute('name'),
                        'posts_count' => $topic->posts_count,
                        'views_count' => $topic->views_count,
                        'created_at' => $topic->created_at,
                    ];
                }
            );
    }
}
