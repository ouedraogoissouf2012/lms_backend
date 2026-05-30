<?php

namespace App\Http\Controllers\API\Dashboard;

use App\Http\Controllers\AuthenticatedController;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * DashboardAdminController — extrait verbatim de DashboardController.
 * Refactor du god-controller (455 lignes -> 3 fichiers SRP).
 * Aucun changement comportemental.
 */
class DashboardAdminController extends AuthenticatedController
{
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
        // TEST-03 : utilise le model pour respecter le scope global
        // `BelongsToInstitution`. `DB::table()` court-circuitait Eloquent
        // et fuitait les forum_posts cross-tenant dans le count.
        $totalForumPosts = ForumPost::count();

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
}
