<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Lesson;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\QuizAttempt;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Contrôleur pour les analytics administrateur
 */
class AdminAnalyticsController extends Controller
{
    /**
     * Récupère les tendances d'activité des utilisateurs (30 derniers jours)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getActivityTrends(Request $request)
    {
        try {
            $tenantUrl = $request->user()?->klassci_tenant_url;
            $institution = app(TenantManager::class)->slug() ?? 'default';
            $cacheKey = 'admin_analytics_activity_trends_' . $institution;
            $cacheTTL = 300; // 5 minutes

            $data = Cache::remember($cacheKey, $cacheTTL, function () use ($tenantUrl) {
                $endDate = Carbon::now();
                $startDate = Carbon::now()->subDays(30);

                // Connexions par jour (approximation via created_at des utilisateurs récents)
                $dailyRegistrations = User::whereBetween('created_at', [$startDate, $endDate])
                    ->when($tenantUrl, fn($q) => $q->where('klassci_tenant_url', $tenantUrl))
                    ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                    ->groupBy('date')
                    ->orderBy('date')
                    ->get();

                // Soumissions d'évaluations par jour
                $dailyEvaluationSubmissions = EvaluationSubmission::whereBetween('submitted_at', [$startDate, $endDate])
                    ->selectRaw('DATE(submitted_at) as date, COUNT(*) as count')
                    ->groupBy('date')
                    ->orderBy('date')
                    ->get();

                // Tentatives de quiz par jour
                $dailyQuizAttempts = QuizAttempt::whereBetween('started_at', [$startDate, $endDate])
                    ->selectRaw('DATE(started_at) as date, COUNT(*) as count')
                    ->groupBy('date')
                    ->orderBy('date')
                    ->get();

                // Générer tous les jours dans la période
                $labels = [];
                $registrations = [];
                $evaluations = [];
                $quizzes = [];

                for ($date = clone $startDate; $date->lte($endDate); $date->addDay()) {
                    $dateString = $date->format('Y-m-d');
                    $labels[] = $date->format('d M');

                    // Chercher les données pour cette date
                    $regCount = $dailyRegistrations->firstWhere('date', $dateString)?->count ?? 0;
                    $evalCount = $dailyEvaluationSubmissions->firstWhere('date', $dateString)?->count ?? 0;
                    $quizCount = $dailyQuizAttempts->firstWhere('date', $dateString)?->count ?? 0;

                    $registrations[] = $regCount;
                    $evaluations[] = $evalCount;
                    $quizzes[] = $quizCount;
                }

                return [
                    'labels' => $labels,
                    'datasets' => [
                        [
                            'label' => 'Inscriptions',
                            'data' => $registrations,
                            'borderColor' => '#3b82f6',
                            'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                            'tension' => 0.4
                        ],
                        [
                            'label' => 'Soumissions Evaluations',
                            'data' => $evaluations,
                            'borderColor' => '#f59e0b',
                            'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                            'tension' => 0.4
                        ],
                        [
                            'label' => 'Tentatives Quiz',
                            'data' => $quizzes,
                            'borderColor' => '#10b981',
                            'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                            'tension' => 0.4
                        ]
                    ]
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des tendances',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupère les métriques système globales
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSystemMetrics(Request $request)
    {
        try {
            $tenantUrl = $request->user()?->klassci_tenant_url;
            $institution = app(TenantManager::class)->slug() ?? 'default';
            $cacheKey = 'admin_analytics_system_metrics_' . $institution;
            $cacheTTL = 300; // 5 minutes

            $data = Cache::remember($cacheKey, $cacheTTL, function () use ($tenantUrl) {
                $now = Carbon::now();
                $weekAgo = Carbon::now()->subWeek();
                $monthAgo = Carbon::now()->subMonth();

                return [
                    // Utilisateurs
                    'users' => [
                        'total' => User::when($tenantUrl, fn($q) => $q->where('klassci_tenant_url', $tenantUrl))->count(),
                        'new_this_week' => User::when($tenantUrl, fn($q) => $q->where('klassci_tenant_url', $tenantUrl))->where('created_at', '>=', $weekAgo)->count(),
                        'new_this_month' => User::when($tenantUrl, fn($q) => $q->where('klassci_tenant_url', $tenantUrl))->where('created_at', '>=', $monthAgo)->count(),
                        'by_role' => User::when($tenantUrl, fn($q) => $q->where('klassci_tenant_url', $tenantUrl))
                            ->selectRaw('role, COUNT(*) as count')
                            ->groupBy('role')
                            ->get()
                            ->pluck('count', 'role')
                    ],

                    // Leçons
                    'lessons' => [
                        'total' => Lesson::count(),
                        'published' => Lesson::where('status', 'published')->count(),
                        'draft' => Lesson::where('status', 'draft')->count(),
                        'created_this_week' => Lesson::where('created_at', '>=', $weekAgo)->count()
                    ],

                    // Evaluations
                    'evaluations' => [
                        'total' => Evaluation::count(),
                        'published' => Evaluation::where('status', 'published')->count(),
                        'submissions_total' => EvaluationSubmission::count(),
                        'submissions_this_week' => EvaluationSubmission::where('submitted_at', '>=', $weekAgo)->count(),
                        'pending_grading' => EvaluationSubmission::whereNull('note_sur_20')->count()
                    ],

                    // Quiz
                    'quizzes' => [
                        'total_attempts' => QuizAttempt::count(),
                        'attempts_this_week' => QuizAttempt::where('started_at', '>=', $weekAgo)->count(),
                        'completed' => QuizAttempt::whereNotNull('completed_at')->count(),
                        'average_score' => QuizAttempt::whereNotNull('score')->avg('score')
                    ],

                    // Performance globale
                    'performance' => [
                        'average_evaluation_score' => EvaluationSubmission::whereNotNull('note_sur_20')->avg('note_sur_20'),
                        'average_quiz_score' => QuizAttempt::whereNotNull('score')->avg('score'),
                        'completion_rate' => $this->calculateCompletionRate()
                    ]
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des métriques',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calcule le taux de completion global
     *
     * @return float
     */
    private function calculateCompletionRate()
    {
        $totalAttempts = QuizAttempt::count();
        if ($totalAttempts === 0) {
            return 0;
        }

        $completedAttempts = QuizAttempt::whereNotNull('completed_at')->count();
        return round(($completedAttempts / $totalAttempts) * 100, 2);
    }

    /**
     * Récupère les tâches en attente pour l'admin
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPendingTasks(Request $request)
    {
        try {
            $tenantUrl = $request->user()?->klassci_tenant_url;
            $institution = app(TenantManager::class)->slug() ?? 'default';
            $cacheKey = 'admin_pending_tasks_' . $institution;
            $cacheTTL = 60; // 1 minute

            $data = Cache::remember($cacheKey, $cacheTTL, function () use ($tenantUrl) {
                return [
                    // Evaluations non notées
                    'pending_grading' => [
                        'count' => EvaluationSubmission::whereNull('note_sur_20')->count(),
                        'urgent_count' => EvaluationSubmission::whereNull('note_sur_20')
                            ->where('submitted_at', '<', Carbon::now()->subDays(3))
                            ->count()
                    ],

                    // Utilisateurs inactifs (30 jours)
                    'inactive_users' => [
                        'count' => User::when($tenantUrl, fn($q) => $q->where('klassci_tenant_url', $tenantUrl))
                            ->where('updated_at', '<', Carbon::now()->subDays(30))
                            ->count()
                    ],

                    // Evaluations non publiées
                    'unpublished_evaluations' => [
                        'count' => Evaluation::where('status', '!=', 'published')->count()
                    ],

                    // Leçons en brouillon
                    'draft_lessons' => [
                        'count' => Lesson::where('status', 'draft')->count()
                    ]
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des tâches',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupère les utilisateurs récents
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRecentUsers(Request $request)
    {
        try {
            $tenantUrl = $request->user()?->klassci_tenant_url;

            $users = User::when($tenantUrl, fn($q) => $q->where('klassci_tenant_url', $tenantUrl))
                ->orderBy('created_at', 'desc')
                ->take(10)
                ->select(['id', 'name', 'email', 'role', 'created_at'])
                ->get();

            return response()->json([
                'success' => true,
                'data' => $users
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des utilisateurs',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
