<?php

namespace App\Http\Controllers\API;

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
 * DashboardController
 *
 * Fournit les données pour les tableaux de bord étudiant et enseignant
 */
class DashboardController extends AuthenticatedController
{
    /**
     * Dashboard Étudiant
     *
     * GET /api/dashboard/student
     *
     * Retourne :
     * - Cours en cours (in_progress)
     * - Cours complétés (completed)
     * - Prochains quiz
     * - Progression globale
     * - Notifications récentes
     * - Activité forum récente
     */
    public function student(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        // 1. Cours en cours
        $ongoingLessons = LessonProgress::query()
            ->where('user_id', $user->id)
            ->where('status', 'in_progress')
            ->with(['lesson.matiere', 'lesson.classe'])
            ->orderBy('last_accessed_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($progress) {
                return [
                    'lesson_id' => $progress->lesson->id,
                    'title' => $progress->lesson->title,
                    'matiere' => $progress->lesson->matiere->libelle ?? null,
                    'progress_percentage' => $progress->progress_percentage,
                    'time_spent_minutes' => $progress->time_spent_minutes,
                    'last_accessed_at' => $progress->last_accessed_at,
                ];
            });

        // 2. Cours complétés (récents)
        $completedLessons = LessonProgress::query()
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->with(['lesson.matiere'])
            ->orderBy('completed_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($progress) {
                return [
                    'lesson_id' => $progress->lesson->id,
                    'title' => $progress->lesson->title,
                    'matiere' => $progress->lesson->matiere->libelle ?? null,
                    'completed_at' => $progress->completed_at,
                    'rating' => $progress->rating,
                ];
            });

        // 3. Prochains quiz (non encore tentés ou non complétés)
        $upcomingQuizzes = Quiz::query()
            ->published()
            ->whereHas('classe', function ($query) use ($user) {
                $query->whereHas('etudiants', function ($q) use ($user) {
                    $q->where('users.id', $user->id);
                });
            })
            ->whereDoesntHave('attempts', function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->where('status', 'completed');
            })
            ->with(['matiere', 'classe'])
            ->orderBy('published_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($quiz) {
                return [
                    'quiz_id' => $quiz->id,
                    'title' => $quiz->title,
                    'matiere' => $quiz->matiere->libelle ?? null,
                    'questions_count' => $quiz->questions_count,
                    'time_limit_minutes' => $quiz->time_limit_minutes,
                    'published_at' => $quiz->published_at,
                ];
            });

        // 4. Progression globale
        $totalLessons = LessonProgress::where('user_id', $user->id)->count();
        $completedLessonsCount = LessonProgress::where('user_id', $user->id)
            ->where('status', 'completed')
            ->count();
        $inProgressCount = LessonProgress::where('user_id', $user->id)
            ->where('status', 'in_progress')
            ->count();

        $averageProgress = LessonProgress::where('user_id', $user->id)
            ->avg('progress_percentage') ?? 0;

        $totalTimeSpent = LessonProgress::where('user_id', $user->id)
            ->sum('time_spent_minutes') ?? 0;

        // Quiz stats
        $totalQuizAttempts = QuizAttempt::where('user_id', $user->id)
            ->where('status', 'completed')
            ->count();

        $averageQuizScore = QuizAttempt::where('user_id', $user->id)
            ->where('status', 'completed')
            ->avg('percentage') ?? 0;

        $progression = [
            'lessons' => [
                'total' => $totalLessons,
                'completed' => $completedLessonsCount,
                'in_progress' => $inProgressCount,
                'average_progress' => round($averageProgress, 1),
                'total_time_spent_minutes' => $totalTimeSpent,
                'total_time_spent_formatted' => $this->formatMinutes($totalTimeSpent),
            ],
            'quizzes' => [
                'total_attempts' => $totalQuizAttempts,
                'average_score' => round($averageQuizScore, 1),
            ],
        ];

        // 5. Notifications récentes (5 dernières)
        $recentNotifications = Notification::query()
            ->forUser($user->id)
            ->recent()
            ->limit(5)
            ->get()
            ->map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'is_read' => $notification->isRead(),
                    'icon' => $notification->getIcon(),
                    'color' => $notification->getColor(),
                    'action_url' => $notification->getActionUrl(),
                    'created_at' => $notification->created_at,
                ];
            });

        $unreadNotificationsCount = Notification::forUser($user->id)->unread()->count();

        // 6. Activité forum récente (topics actifs dans les classes de l'étudiant)
        $recentForumActivity = ForumTopic::query()
            ->whereHas('classe', function ($query) use ($user) {
                $query->whereHas('etudiants', function ($q) use ($user) {
                    $q->where('users.id', $user->id);
                });
            })
            ->with(['user', 'matiere'])
            ->orderBy('last_activity_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($topic) {
                return [
                    'topic_id' => $topic->id,
                    'title' => $topic->title,
                    'author' => $topic->user->name,
                    'matiere' => $topic->matiere->libelle ?? null,
                    'posts_count' => $topic->posts_count,
                    'is_resolved' => $topic->is_resolved,
                    'last_activity_at' => $topic->last_activity_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'ongoing_lessons' => $ongoingLessons,
                'completed_lessons' => $completedLessons,
                'upcoming_quizzes' => $upcomingQuizzes,
                'progression' => $progression,
                'notifications' => [
                    'recent' => $recentNotifications,
                    'unread_count' => $unreadNotificationsCount,
                ],
                'forum_activity' => $recentForumActivity,
            ],
        ]);
    }

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

        return response()->json([
            'success' => true,
            'data' => [
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
            ],
        ]);
    }

    /**
     * Statistiques globales (Admin/Coordinateur)
     *
     * GET /api/dashboard/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $tenantUrl = $this->authenticatedUser($request)->klassci_tenant_url;

        $totalUsers = User::when($tenantUrl, fn($q) => $q->where('klassci_tenant_url', $tenantUrl))->count();
        $totalStudents = User::when($tenantUrl, fn($q) => $q->where('klassci_tenant_url', $tenantUrl))->where('role', 'etudiant')->count();
        $totalTeachers = User::when($tenantUrl, fn($q) => $q->where('klassci_tenant_url', $tenantUrl))->where('role', 'enseignant')->count();

        $totalLessons = Lesson::count();
        $publishedLessons = Lesson::where('status', 'published')->count();

        $totalQuizzes = Quiz::count();
        $totalQuizAttempts = QuizAttempt::where('status', 'completed')->count();

        $totalForumTopics = ForumTopic::count();
        $totalForumPosts = DB::table('forum_posts')->count();

        $totalNotifications = Notification::count();
        $unreadNotifications = Notification::whereNull('read_at')->count();

        // Activité récente (7 derniers jours)
        $recentActivity = [
            'new_users' => User::when($tenantUrl, fn($q) => $q->where('klassci_tenant_url', $tenantUrl))->where('created_at', '>=', now()->subDays(7))->count(),
            'new_lessons' => Lesson::where('created_at', '>=', now()->subDays(7))->count(),
            'new_quiz_attempts' => QuizAttempt::where('created_at', '>=', now()->subDays(7))->count(),
            'new_forum_topics' => ForumTopic::where('created_at', '>=', now()->subDays(7))->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'users' => [
                    'total' => $totalUsers,
                    'students' => $totalStudents,
                    'teachers' => $totalTeachers,
                ],
                'lessons' => [
                    'total' => $totalLessons,
                    'published' => $publishedLessons,
                ],
                'quizzes' => [
                    'total' => $totalQuizzes,
                    'total_attempts' => $totalQuizAttempts,
                ],
                'forum' => [
                    'total_topics' => $totalForumTopics,
                    'total_posts' => $totalForumPosts,
                ],
                'notifications' => [
                    'total' => $totalNotifications,
                    'unread' => $unreadNotifications,
                ],
                'recent_activity' => $recentActivity,
            ],
        ]);
    }

    /**
     * Formater les minutes en heures et minutes
     */
    private function formatMinutes(int $minutes): string
    {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        if ($hours > 0) {
            return "{$hours}h {$mins}min";
        }

        return "{$mins}min";
    }
}
