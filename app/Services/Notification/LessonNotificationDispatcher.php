<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Lesson;
use App\Models\Notification;
use App\Models\User;

/**
 * Notifications liées aux leçons (publication, mise à jour).
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
     * Notifier la publication d'un nouveau cours aux étudiants de la classe.
     */
    public function notifyLessonPublished(Lesson $lesson): int
    {
        $students = User::query()
            ->whereHas('classes', function ($query) use ($lesson) {
                $query->where('classes.id', $lesson->classe_id);
            })
            ->where('role', 'etudiant')
            ->get();

        if ($students->isEmpty()) {
            return 0;
        }

        $title = "Nouveau cours disponible";
        $message = "Le cours \"{$lesson->title}\" a été publié dans {$lesson->matiere->libelle}.";
        $data = [
            'lesson_id' => $lesson->id,
            'lesson_title' => $lesson->title,
            'matiere' => $lesson->matiere->libelle ?? null,
        ];

        return $this->dispatcher->sendToMany($students, Notification::TYPE_LESSON_PUBLISHED, $title, $message, $data);
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
