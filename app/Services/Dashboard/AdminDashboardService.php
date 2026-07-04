<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Agrégation des statistiques globales admin/coordinateur (issue #364).
 *
 * Extrait verbatim de `DashboardAdminController::stats`. Deux mécanismes
 * d'isolation coexistent, préservés tels quels :
 *
 *   - les modèles de contenu (Lesson, Quiz, QuizAttempt, ForumTopic,
 *     ForumPost, Notification) sont isolés par le scope global
 *     `BelongsToInstitution` (TEST-03 : ne jamais repasser par
 *     `DB::table()` qui le court-circuite) ;
 *   - les Users cumulent ce même scope global ET le filtre manuel par
 *     `klassci_tenant_url` (`when($tenantUrl, ...)` → si l'URL du
 *     coordinateur est null, seul le scope institution subsiste —
 *     comportement hérité, verrouillé par AdminDashboardServiceTest).
 *
 * ## SRP / DI (§1.6)
 *
 * Une seule responsabilité : produire le payload de stats globales pour
 * l'utilisateur staff donné. Aucune dépendance à injecter — requêtes via
 * les modèles Eloquent, cohérent avec {@see StudentDashboardService}.
 *
 * @see app/Http/Controllers/API/Dashboard/DashboardAdminController.php
 */
final class AdminDashboardService
{
    /**
     * Fenêtre de l'activité récente, en jours.
     */
    private const RECENT_DAYS = 7;

    /**
     * Construire le payload complet des statistiques globales.
     *
     * @return array<string, mixed>
     */
    public function buildStats(User $user): array
    {
        $tenantUrl = $user->klassci_tenant_url;

        return [
            'users' => $this->userCounts($tenantUrl),
            'lessons' => [
                'total' => Lesson::count(),
                'published' => Lesson::where('status', 'published')->count(),
            ],
            'quizzes' => [
                'total' => Quiz::count(),
                'total_attempts' => QuizAttempt::where('status', 'completed')->count(),
            ],
            'forum' => [
                'total_topics' => ForumTopic::count(),
                // TEST-03 : utilise le model pour respecter le scope global
                // `BelongsToInstitution`. `DB::table()` court-circuitait Eloquent
                // et fuitait les forum_posts cross-tenant dans le count.
                'total_posts' => ForumPost::count(),
            ],
            'notifications' => [
                'total' => Notification::count(),
                'unread' => Notification::whereNull('read_at')->count(),
            ],
            'recent_activity' => $this->recentActivity($tenantUrl),
        ];
    }

    /**
     * Compteurs users filtrés par l'URL tenant KLASSCI du coordinateur.
     *
     * @return array<string, int>
     */
    private function userCounts(?string $tenantUrl): array
    {
        return [
            'total' => $this->tenantUsersQuery($tenantUrl)->count(),
            'students' => $this->tenantUsersQuery($tenantUrl)->where('role', 'etudiant')->count(),
            'teachers' => $this->tenantUsersQuery($tenantUrl)->where('role', 'enseignant')->count(),
        ];
    }

    /**
     * Activité des 7 derniers jours (créations récentes).
     *
     * @return array<string, int>
     */
    private function recentActivity(?string $tenantUrl): array
    {
        $since = now()->subDays(self::RECENT_DAYS);

        return [
            'new_users' => $this->tenantUsersQuery($tenantUrl)->where('created_at', '>=', $since)->count(),
            'new_lessons' => Lesson::where('created_at', '>=', $since)->count(),
            'new_quiz_attempts' => QuizAttempt::where('created_at', '>=', $since)->count(),
            'new_forum_topics' => ForumTopic::where('created_at', '>=', $since)->count(),
        ];
    }

    /**
     * Base commune des requêtes users : filtre `klassci_tenant_url`
     * uniquement quand le coordinateur en possède une (verbatim `when()`).
     *
     * @return Builder<User>
     */
    private function tenantUsersQuery(?string $tenantUrl): Builder
    {
        return User::when($tenantUrl, fn ($q) => $q->where('klassci_tenant_url', $tenantUrl));
    }
}
