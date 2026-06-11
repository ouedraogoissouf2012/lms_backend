<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\ForumTopic;
use App\Models\LessonProgress;
use App\Models\Notification;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\Notification\NotificationPresenter;
use Illuminate\Support\Collection;

/**
 * Agrégation du dashboard étudiant (split-26/dashboard-student).
 *
 * Extrait verbatim de `DashboardStudentController::student` : centralise
 * la construction du payload du dashboard étudiant (cours en cours,
 * cours complétés, prochains quiz, progression, notifications, activité
 * forum). Aucun changement comportemental ; les requêtes Eloquent, les
 * limites (5 éléments par bloc) et les projections de champs sont
 * conservées telles quelles depuis le god-controller.
 *
 * ## DI strict (§1.6 D)
 *
 * `NotificationPresenter` est injecté par le constructeur (jamais `app()`
 * ni Facade). Aucune autre dépendance n'est nécessaire — les requêtes
 * passent par les modèles Eloquent (cohérent avec la version pré-split).
 *
 * ## SRP
 *
 * Une seule responsabilité publique : produire l'array du dashboard
 * étudiant pour un `User` donné. Les blocs sont des méthodes privées
 * dédiées (un par section du payload) pour respecter §1.1 (services
 * ≤300 l) et faciliter la maintenance.
 *
 * @see app/Http/Controllers/API/Dashboard/DashboardStudentController.php
 */
final class StudentDashboardService
{
    /**
     * Nombre maximum d'éléments retournés pour chaque bloc.
     */
    private const BLOCK_LIMIT = 5;

    public function __construct(
        private readonly NotificationPresenter $presenter,
    ) {
    }

    /**
     * Construire le payload complet du dashboard étudiant.
     *
     * @return array<string, mixed>
     */
    public function buildDashboard(User $user): array
    {
        return [
            'ongoing_lessons' => $this->ongoingLessons($user),
            'completed_lessons' => $this->completedLessons($user),
            'upcoming_quizzes' => $this->upcomingQuizzes($user),
            'progression' => $this->progression($user),
            'notifications' => [
                'recent' => $this->recentNotifications($user),
                'unread_count' => $this->unreadNotificationsCount($user),
            ],
            'forum_activity' => $this->recentForumActivity($user),
        ];
    }

    /**
     * Cours en cours (in_progress) — 5 derniers accédés.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function ongoingLessons(User $user): Collection
    {
        return LessonProgress::query()
            ->where('user_id', $user->id)
            ->where('status', 'in_progress')
            ->with(['lesson.matiere', 'lesson.classe'])
            ->orderBy('last_accessed_at', 'desc')
            ->limit(self::BLOCK_LIMIT)
            ->get()
            ->map(function ($progress): array {
                return [
                    'lesson_id' => $progress->lesson->id,
                    'title' => $progress->lesson->title,
                    'matiere' => $progress->lesson->matiere->libelle ?? null,
                    'progress_percentage' => $progress->progress_percentage,
                    'time_spent_minutes' => $progress->time_spent_minutes,
                    'last_accessed_at' => $progress->last_accessed_at,
                ];
            });
    }

    /**
     * Cours complétés (récents) — 5 derniers terminés.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function completedLessons(User $user): Collection
    {
        return LessonProgress::query()
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->with(['lesson.matiere'])
            ->orderBy('completed_at', 'desc')
            ->limit(self::BLOCK_LIMIT)
            ->get()
            ->map(function ($progress): array {
                return [
                    'lesson_id' => $progress->lesson->id,
                    'title' => $progress->lesson->title,
                    'matiere' => $progress->lesson->matiere->libelle ?? null,
                    'completed_at' => $progress->completed_at,
                    'rating' => $progress->rating,
                ];
            });
    }

    /**
     * Prochains quiz (publiés, non complétés par l'étudiant).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function upcomingQuizzes(User $user): Collection
    {
        return Quiz::query()
            ->published()
            ->whereHas('classe', function ($query) use ($user): void {
                $query->whereHas('etudiants', function ($q) use ($user): void {
                    $q->where('users.id', $user->id);
                });
            })
            ->whereDoesntHave('attempts', function ($query) use ($user): void {
                $query->where('user_id', $user->id)
                    ->where('status', 'completed');
            })
            ->with(['matiere', 'classe'])
            ->orderBy('published_at', 'desc')
            ->limit(self::BLOCK_LIMIT)
            ->get()
            ->map(function ($quiz): array {
                return [
                    'quiz_id' => $quiz->id,
                    'title' => $quiz->title,
                    'matiere' => $quiz->matiere->libelle ?? null,
                    'questions_count' => $quiz->questions_count,
                    'time_limit_minutes' => $quiz->time_limit_minutes,
                    'published_at' => $quiz->published_at,
                ];
            });
    }

    /**
     * Progression globale : stats leçons + stats quiz.
     *
     * @return array<string, array<string, mixed>>
     */
    private function progression(User $user): array
    {
        $totalLessons = LessonProgress::where('user_id', $user->id)->count();

        $completedLessonsCount = LessonProgress::where('user_id', $user->id)
            ->where('status', 'completed')
            ->count();

        $inProgressCount = LessonProgress::where('user_id', $user->id)
            ->where('status', 'in_progress')
            ->count();

        $averageProgress = LessonProgress::where('user_id', $user->id)
            ->avg('progress_percentage') ?? 0;

        $totalTimeSpent = (int) (LessonProgress::where('user_id', $user->id)
            ->sum('time_spent_minutes') ?? 0);

        $totalQuizAttempts = QuizAttempt::where('user_id', $user->id)
            ->where('status', 'completed')
            ->count();

        $averageQuizScore = QuizAttempt::where('user_id', $user->id)
            ->where('status', 'completed')
            ->avg('percentage') ?? 0;

        return [
            'lessons' => [
                'total' => $totalLessons,
                'completed' => $completedLessonsCount,
                'in_progress' => $inProgressCount,
                'average_progress' => round((float) $averageProgress, 1),
                'total_time_spent_minutes' => $totalTimeSpent,
                'total_time_spent_formatted' => $this->formatMinutes($totalTimeSpent),
            ],
            'quizzes' => [
                'total_attempts' => $totalQuizAttempts,
                'average_score' => round((float) $averageQuizScore, 1),
            ],
        ];
    }

    /**
     * Notifications récentes (5 dernières) — projection via presenter.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function recentNotifications(User $user): Collection
    {
        return Notification::query()
            ->forUser($user->id)
            ->recent()
            ->limit(self::BLOCK_LIMIT)
            ->get()
            ->map(function ($notification): array {
                return [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'is_read' => $this->presenter->isRead($notification),
                    'icon' => $this->presenter->icon($notification),
                    'color' => $this->presenter->color($notification),
                    'action_url' => $this->presenter->getActionUrl($notification),
                    'created_at' => $notification->created_at,
                ];
            });
    }

    /**
     * Compteur des notifications non lues (badge).
     */
    private function unreadNotificationsCount(User $user): int
    {
        return Notification::forUser($user->id)->unread()->count();
    }

    /**
     * Activité forum récente : topics actifs dans les classes de l'étudiant.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function recentForumActivity(User $user): Collection
    {
        return ForumTopic::query()
            ->whereHas('classe', function ($query) use ($user): void {
                $query->whereHas('etudiants', function ($q) use ($user): void {
                    $q->where('users.id', $user->id);
                });
            })
            ->with(['user', 'matiere'])
            ->orderBy('last_activity_at', 'desc')
            ->limit(self::BLOCK_LIMIT)
            ->get()
            ->map(function ($topic): array {
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
    }

    /**
     * Formater les minutes en heures et minutes (verbatim du controller).
     */
    private function formatMinutes(int $minutes): string
    {
        $hours = (int) floor($minutes / 60);
        $mins = $minutes % 60;

        if ($hours > 0) {
            return "{$hours}h {$mins}min";
        }

        return "{$mins}min";
    }
}
