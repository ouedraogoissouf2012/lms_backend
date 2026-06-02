<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;

/**
 * Notifications liées aux quiz (disponibilité, note reçue, deadline).
 *
 * Extrait de `NotificationService` (split notification-service, §1.1 ≤300 l).
 *
 * ## DI strict (§1.6 D)
 *
 * Délègue l'envoi bas niveau à `NotificationDispatcher` injecté.
 *
 * @see app/Services/NotificationService.php  Facade orchestrateur
 */
final class QuizNotificationDispatcher
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
    ) {
    }

    /**
     * Notifier la disponibilité d'un nouveau quiz aux étudiants de la classe.
     */
    public function notifyQuizAvailable(Quiz $quiz): int
    {
        $students = User::query()
            ->whereHas('classes', function ($query) use ($quiz) {
                $query->where('classes.id', $quiz->classe_id);
            })
            ->where('role', 'etudiant')
            ->get();

        if ($students->isEmpty()) {
            return 0;
        }

        $title = "Nouveau quiz disponible";
        $message = "Le quiz \"{$quiz->title}\" est maintenant disponible.";
        $data = [
            'quiz_id' => $quiz->id,
            'quiz_title' => $quiz->title,
            'matiere' => $quiz->matiere->libelle ?? null,
        ];

        return $this->dispatcher->sendToMany($students, Notification::TYPE_QUIZ_AVAILABLE, $title, $message, $data);
    }

    /**
     * Notifier la réception d'une note de quiz à l'étudiant concerné.
     */
    public function notifyGradeReceived(QuizAttempt $attempt): int
    {
        $student = $attempt->user;
        $quiz = $attempt->quiz;

        $title = "Note reçue";
        $message = "Vous avez reçu une note de {$attempt->score}/{$attempt->max_score} pour le quiz \"{$quiz->title}\".";
        $data = [
            'quiz_id' => $quiz->id,
            'attempt_id' => $attempt->id,
            'quiz_title' => $quiz->title,
            'score' => $attempt->score,
            'max_score' => $attempt->max_score,
            'percentage' => $attempt->percentage,
        ];

        return $this->dispatcher->send($student, Notification::TYPE_GRADE_RECEIVED, $title, $message, $data) ? 1 : 0;
    }

    /**
     * Notifier la date limite d'un quiz aux étudiants n'ayant pas encore participé.
     */
    public function notifyQuizDeadline(Quiz $quiz, int $daysRemaining = 1): int
    {
        $students = User::query()
            ->whereHas('classes', function ($query) use ($quiz) {
                $query->where('classes.id', $quiz->classe_id);
            })
            ->where('role', 'etudiant')
            ->whereDoesntHave('quizAttempts', function ($query) use ($quiz) {
                $query->where('quiz_id', $quiz->id)
                    ->where('status', 'completed');
            })
            ->get();

        if ($students->isEmpty()) {
            return 0;
        }

        $dayText = $daysRemaining > 1 ? "$daysRemaining jours" : "1 jour";
        $title = "Date limite de quiz";
        $message = "Le quiz \"{$quiz->title}\" se termine dans $dayText !";
        $data = [
            'quiz_id' => $quiz->id,
            'quiz_title' => $quiz->title,
            'days_remaining' => $daysRemaining,
        ];

        return $this->dispatcher->sendToMany($students, Notification::TYPE_QUIZ_DEADLINE, $title, $message, $data);
    }
}
