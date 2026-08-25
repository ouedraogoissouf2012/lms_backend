<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Notification;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\QuizNotificationDispatcher;
use App\Services\Notification\VisioNotificationDispatcher;
use Illuminate\Support\Collection;

/**
 * NotificationService — facade orchestrateur sur les dispatchers de notifications.
 *
 * ## Pourquoi cette facade
 *
 * Le code métier est éclaté en dispatchers SRP dans `App\Services\Notification\`
 * pour respecter §1.1 (≤300 lignes / service) ; cette classe en est le point
 * d'entrée unique pour les callers applicatifs (`SyncKlassciSeances`,
 * `LMSVisioLifecycleController`, `routes/console.php`).
 *
 * ## Périmètre réduit par #500
 *
 * Les 4 méthodes leçon/forum et leurs deux dispatchers ont été retirés : ils
 * n'avaient AUCUN appelant, et le travail est déjà fait ailleurs, mieux —
 * `DispatchLessonPublishedNotifications` (qui restaure le tenant) pour les
 * leçons, `ForumPostService` en inline (avec `institution_id`, sémantique
 * volontairement distincte, cf. son docblock) pour le forum. Les rebrancher
 * aurait produit des notifications en double.
 *
 * **Invariant à tenir** : chaque méthode restante a au moins un appelant.
 * Toute méthode ajoutée ici sans appelant est du code mort (§1.1 / YAGNI).
 *
 * ## DI strict (§1.6 D)
 *
 * Tous les dispatchers sont injectés par le container (constructor injection).
 * Aucun appel à `app()` ou Facades. Les méthodes sont des thin wrappers qui
 * délèguent au bon dispatcher selon le domaine métier.
 *
 * @see app/Services/Notification/NotificationDispatcher.php      Primitive bas niveau
 * @see app/Services/Notification/QuizNotificationDispatcher.php  Concerns quiz
 * @see app/Services/Notification/VisioNotificationDispatcher.php Concerns visio
 */
final class NotificationService
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly QuizNotificationDispatcher $quizDispatcher,
        private readonly VisioNotificationDispatcher $visioDispatcher,
    ) {
    }

    /**
     * Envoyer une notification à un utilisateur.
     *
     * @param  array<string, mixed>  $data
     */
    public function send(User $user, string $type, string $title, string $message, array $data = []): Notification
    {
        return $this->dispatcher->send($user, $type, $title, $message, $data);
    }

    /**
     * Envoyer une notification à plusieurs utilisateurs.
     *
     * @param  Collection<int, User>  $users
     * @param  array<string, mixed>  $data
     */
    public function sendToMany(Collection $users, string $type, string $title, string $message, array $data = []): int
    {
        return $this->dispatcher->sendToMany($users, $type, $title, $message, $data);
    }

    /**
     * Notifier la disponibilité d'un nouveau quiz.
     */
    public function notifyQuizAvailable(Quiz $quiz): int
    {
        return $this->quizDispatcher->notifyQuizAvailable($quiz);
    }

    /**
     * Notifier la réception d'une note de quiz.
     */
    public function notifyGradeReceived(QuizAttempt $attempt): int
    {
        return $this->quizDispatcher->notifyGradeReceived($attempt);
    }

    /**
     * Notifier la date limite d'un quiz.
     */
    public function notifyQuizDeadline(Quiz $quiz, int $daysRemaining = 1): int
    {
        return $this->quizDispatcher->notifyQuizDeadline($quiz, $daysRemaining);
    }

    /**
     * Notifier la programmation d'une visioconférence.
     *
     * @param  array<string, mixed>  $seanceData
     */
    public function notifyVisioScheduled(int $seanceId, array $seanceData): int
    {
        return $this->visioDispatcher->notifyVisioScheduled($seanceId, $seanceData);
    }

    /**
     * Notifier le démarrage imminent d'une visioconférence.
     *
     * @param  array<string, mixed>  $seanceData
     */
    public function notifyVisioStarting(int $seanceId, array $seanceData): int
    {
        return $this->visioDispatcher->notifyVisioStarting($seanceId, $seanceData);
    }

    /**
     * Supprimer les anciennes notifications (cleanup).
     *
     * @param  int  $days  Nombre de jours à conserver (défaut: 30)
     */
    public function cleanupOldNotifications(int $days = 30): int
    {
        return $this->dispatcher->cleanupOldNotifications($days);
    }
}
