<?php

namespace App\Http\Controllers\API\Dashboard;

use App\Http\Controllers\AuthenticatedController;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\ForumTopic;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * DashboardTeacherController — extrait verbatim de DashboardController.
 * Refactor du god-controller (455 lignes -> 3 fichiers SRP).
 * Aucun changement comportemental.
 */
class DashboardTeacherController extends AuthenticatedController
{
    /**
     * Dashboard Enseignant
     *
     * GET /api/dashboard/teacher
     *
     * Retourne :
     * - Statistiques cours créés
     * - Étudiants actifs
     * - Quiz à corriger
     * - Topics forum non résolus
     * - Engagement des étudiants
     */
    public function teacher(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        // 1. Statistiques cours créés
        $totalLessons = Lesson::where('enseignant_id', $user->id)->count();
        $publishedLessons = Lesson::where('enseignant_id', $user->id)
            ->where('status', 'published')
            ->count();
        $draftLessons = Lesson::where('enseignant_id', $user->id)
            ->where('status', 'draft')
            ->count();

        // Cours avec le plus d'engagement
        // PERF-03 — Avant : 1 query main + 5×2 sub-queries (count + avg par lesson) = 11 queries.
        // Après : 4 queries totales via `withCount`/`withAvg` agrégés en une passe SQL.
        $topLessons = Lesson::query()
            ->where('enseignant_id', $user->id)
            ->where('status', 'published')
            ->withCount(['progress as students_started' => function ($query) {
                $query->whereIn('status', ['in_progress', 'completed']);
            }])
            ->withCount(['progress as students_completed' => function ($query) {
                $query->where('status', 'completed');
            }])
            ->withAvg('progress as average_progress', 'progress_percentage')
            ->orderBy('students_started', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($lesson) {
                return [
                    'lesson_id'          => $lesson->id,
                    'title'              => $lesson->title,
                    'students_started'   => $lesson->students_started,
                    'students_completed' => $lesson->students_completed ?? 0,
                    'average_progress'   => round((float) ($lesson->average_progress ?? 0), 1),
                ];
            });

        // 2. Étudiants actifs (ont accédé à un cours dans les 7 derniers jours)
        $activeStudentsCount = LessonProgress::query()
            ->whereHas('lesson', function ($query) use ($user) {
                $query->where('enseignant_id', $user->id);
            })
            ->where('last_accessed_at', '>=', now()->subDays(7))
            ->distinct('user_id')
            ->count('user_id');

        // 3. Quiz à corriger (tentatives en attente de correction manuelle)
        $quizzesToGrade = QuizAttempt::query()
            ->whereHas('quiz', function ($query) use ($user) {
                $query->where('enseignant_id', $user->id);
            })
            ->where('status', 'completed')
            ->whereNull('graded_at')
            ->whereHas('quiz', function ($query) {
                $query->where('requires_manual_grading', true);
            })
            ->with(['quiz', 'user'])
            ->orderBy('completed_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($attempt) {
                return [
                    'attempt_id' => $attempt->id,
                    'quiz_title' => $attempt->quiz->title,
                    'student_name' => $attempt->user->name,
                    'completed_at' => $attempt->completed_at,
                    'auto_score' => $attempt->score,
                ];
            });

        $quizzesToGradeCount = QuizAttempt::query()
            ->whereHas('quiz', function ($query) use ($user) {
                $query->where('enseignant_id', $user->id);
            })
            ->where('status', 'completed')
            ->whereNull('graded_at')
            ->whereHas('quiz', function ($query) {
                $query->where('requires_manual_grading', true);
            })
            ->count();

        // 4. Topics forum non résolus dans les cours de l'enseignant
        $unresolvedTopics = ForumTopic::query()
            ->whereHas('lesson', function ($query) use ($user) {
                $query->where('enseignant_id', $user->id);
            })
            ->where('is_resolved', false)
            ->where('status', 'open')
            ->with(['user', 'lesson'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($topic) {
                return [
                    'topic_id' => $topic->id,
                    'title' => $topic->title,
                    'lesson_title' => $topic->lesson->title ?? null,
                    'author' => $topic->user->name,
                    'posts_count' => $topic->posts_count,
                    'views_count' => $topic->views_count,
                    'created_at' => $topic->created_at,
                ];
            });

        $unresolvedTopicsCount = ForumTopic::query()
            ->whereHas('lesson', function ($query) use ($user) {
                $query->where('enseignant_id', $user->id);
            })
            ->where('is_resolved', false)
            ->where('status', 'open')
            ->count();

        // 5. Statistiques quiz
        $totalQuizzes = Quiz::where('enseignant_id', $user->id)->count();
        $publishedQuizzes = Quiz::where('enseignant_id', $user->id)
            ->where('status', 'published')
            ->count();

        $totalQuizAttempts = QuizAttempt::query()
            ->whereHas('quiz', function ($query) use ($user) {
                $query->where('enseignant_id', $user->id);
            })
            ->where('status', 'completed')
            ->count();

        $averageQuizScore = QuizAttempt::query()
            ->whereHas('quiz', function ($query) use ($user) {
                $query->where('enseignant_id', $user->id);
            })
            ->where('status', 'completed')
            ->avg('percentage') ?? 0;

        return $this->successResponse([
            'lessons' => [
                'total' => $totalLessons,
                'published' => $publishedLessons,
                'draft' => $draftLessons,
                'top_lessons' => $topLessons,
            ],
            'students' => [
                'active_last_7_days' => $activeStudentsCount,
            ],
            'quizzes' => [
                'total' => $totalQuizzes,
                'published' => $publishedQuizzes,
                'to_grade' => $quizzesToGradeCount,
                'to_grade_list' => $quizzesToGrade,
                'total_attempts' => $totalQuizAttempts,
                'average_score' => round($averageQuizScore, 1),
            ],
            'forum' => [
                'unresolved_topics' => $unresolvedTopicsCount,
                'unresolved_topics_list' => $unresolvedTopics,
            ],
        ]);
    }
}
