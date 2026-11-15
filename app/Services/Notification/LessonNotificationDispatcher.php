<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Lesson;
use App\Models\Notification;
use App\Models\User;

/**
 * Notifications liées aux leçons (mise à jour).
 *
 * La publication n'est PLUS ici (D614) : `DispatchLessonPublishedNotifications`
 * avertit les élèves de la classe par la règle partagée (`ElevesDeLaClasse`). La
 * méthode d'ici n'avait aucun appelant et portait une seconde règle (le pivot sans
 * le filtre `actif`, un rôle écrit en dur) : elle est retirée.
 *
 * Extrait de `NotificationService` (split notification-service, §1.1 ≤300 l).
 *
 * ## DI strict (§1.6 D)
 *
 * Délègue l'envoi bas niveau à `NotificationDispatcher` injecté.
 *
 * @see app/Services/NotificationService.php  Facade orchestrateur
 */
final class LessonNotificationDispatcher
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
    ) {
    }

    /**
     * Notifier la mise à jour d'un cours aux étudiants qui l'ont commencé.
     */
    public function notifyLessonUpdated(Lesson $lesson): int
    {
        $students = User::query()
            ->whereHas('lessonProgress', function ($query) use ($lesson) {
                $query->where('lesson_id', $lesson->id)
                    ->whereIn('status', ['in_progress', 'completed']);
            })
            ->get();

        if ($students->isEmpty()) {
            return 0;
        }

        $title = "Cours mis à jour";
        $message = "Le cours \"{$lesson->title}\" a été mis à jour.";
        $data = [
            'lesson_id' => $lesson->id,
            'lesson_title' => $lesson->title,
        ];

        return $this->dispatcher->sendToMany($students, Notification::TYPE_LESSON_UPDATED, $title, $message, $data);
    }
}
