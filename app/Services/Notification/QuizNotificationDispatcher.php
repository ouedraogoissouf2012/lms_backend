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
        // Annoncer « maintenant disponible » alors que l'ouverture est
        // programmée plus tard serait faux. Un quiz à ouverture différée n'est
        // donc pas annoncé ici (dette déclarée : aucun émetteur ne prend le
        // relais à l'heure d'ouverture — voir requirements §4).
        if ($quiz->available_from !== null && $quiz->available_from->isFuture()) {
            return 0;
        }

        $students = $this->activeStudentsQuery($quiz)->get();

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

        // `QuizAttempt::user()` est un `belongsTo` nu et `User` porte
        // `SoftDeletes` : corriger la copie d'un étudiant désactivé renvoie
        // `null` ici. Sans cette garde, la note était bien enregistrée puis
        // l'envoi levait un TypeError — 500 permanent sur l'endpoint de
        // notation. Un compte désactivé n'a de toute façon pas à être notifié.
        if (! $student instanceof User) {
            return 0;
        }

        // #500 — `max_score` et `percentage` n'existent PAS sur `quiz_attempts`
        // (ni colonne, ni accessor) : le message rendait « note de 15.00/ » et
        // `data.percentage` valait null. Les colonnes réelles sont
        // `points_earned` / `points_possible`, et `score` EST le pourcentage
        // 0-100 (QuizGradingService.php:177 et :229). Les clés de `data` sont
        // conservées : aucune notification n'ayant jamais été émise, il n'y a
        // pas de contrat client à casser, et le front les attend sous ces noms.
        $title = "Note reçue";
        $message = "Vous avez reçu une note de {$attempt->points_earned}/{$attempt->points_possible} pour le quiz \"{$quiz->title}\".";
        $data = [
            'quiz_id' => $quiz->id,
            'attempt_id' => $attempt->id,
            'quiz_title' => $quiz->title,
            'score' => $attempt->points_earned,
            'max_score' => $attempt->points_possible,
            'percentage' => $attempt->score,
        ];

        return $this->dispatcher->send($student, Notification::TYPE_GRADE_RECEIVED, $title, $message, $data) ? 1 : 0;
    }

    /**
     * Notifier la date limite d'un quiz aux étudiants n'ayant pas encore participé.
     */
    public function notifyQuizDeadline(Quiz $quiz, int $daysRemaining = 1): int
    {
        $students = $this->activeStudentsQuery($quiz)
            // #500 — filtrait `status = 'completed'`, valeur absente de
            // l'énumération : la sous-requête ne remontait rien, l'exclusion
            // était donc toujours vraie et le rappel serait parti aussi aux
            // étudiants ayant déjà rendu. `COMPLETED_STATUSES` est la
            // définition unique du concept (#608). Sous-requête SQL, pas de
            // filtrage en PHP : le comptage reste à 1 requête (§1.4).
            ->whereDoesntHave('quizAttempts', function ($query) use ($quiz) {
                $query->where('quiz_id', $quiz->id)
                    ->whereIn('status', QuizAttempt::COMPLETED_STATUSES);
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

    /**
     * Étudiants ACTIVEMENT inscrits à la classe du quiz.
     *
     * Le pivot `classe_etudiant.statut` fait foi : un étudiant désinscrit ne
     * doit pas être notifié. Même règle que `VisioNotificationDispatcher:152`
     * et `Classe::etudiantsActifs()` — les deux fan-outs quiz l'ignoraient,
     * ce qui n'était sans conséquence que parce qu'ils n'étaient jamais
     * appelés (#500).
     *
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function activeStudentsQuery(Quiz $quiz): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()
            ->whereHas('classes', function ($query) use ($quiz) {
                $query->where('classes.id', $quiz->classe_id)
                    ->where('classe_etudiant.statut', 'actif');
            })
            ->where('role', 'etudiant');
    }
}
