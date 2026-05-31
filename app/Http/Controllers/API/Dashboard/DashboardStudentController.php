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
use App\Services\Notification\NotificationPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * DashboardStudentController — extrait verbatim de DashboardController.
 * Refactor du god-controller (455 lignes -> 3 fichiers SRP).
 * Aucun changement comportemental.
 */
class DashboardStudentController extends AuthenticatedController
{
    public function __construct(private NotificationPresenter $presenter)
    {
    }

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
                    'action_url' => $this->presenter->getActionUrl($notification),
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
