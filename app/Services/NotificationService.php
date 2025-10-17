<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Models\Lesson;
use App\Models\ForumTopic;
use App\Models\ForumPost;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Support\Collection;

/**
 * NotificationService
 *
 * Service pour gérer l'envoi de notifications
 */
class NotificationService
{
    /**
     * Envoyer une notification à un utilisateur
     */
    public function send(User $user, string $type, string $title, string $message, array $data = []): Notification
    {
        return Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * Envoyer une notification à plusieurs utilisateurs
     */
    public function sendToMany(Collection $users, string $type, string $title, string $message, array $data = []): int
    {
        $count = 0;

        foreach ($users as $user) {
            $this->send($user, $type, $title, $message, $data);
            $count++;
        }

        return $count;
    }

    /**
     * Notifier la publication d'un nouveau cours
     */
    public function notifyLessonPublished(Lesson $lesson): int
    {
        // Récupérer les étudiants de la classe
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

        return $this->sendToMany($students, Notification::TYPE_LESSON_PUBLISHED, $title, $message, $data);
    }

    /**
     * Notifier la mise à jour d'un cours
     */
    public function notifyLessonUpdated(Lesson $lesson): int
    {
        // Récupérer les étudiants qui ont commencé le cours
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

        return $this->sendToMany($students, Notification::TYPE_LESSON_UPDATED, $title, $message, $data);
    }

    /**
     * Notifier une réponse dans le forum
     */
    public function notifyForumReply(ForumPost $post): int
    {
        $topic = $post->topic;
        $author = $topic->user;

        // Ne pas notifier si l'auteur du post est aussi l'auteur du topic
        if ($post->user_id === $author->id) {
            return 0;
        }

        $title = "Nouvelle réponse à votre topic";
        $message = "{$post->user->name} a répondu à votre topic \"{$topic->title}\".";
        $data = [
            'topic_id' => $topic->id,
            'post_id' => $post->id,
            'topic_title' => $topic->title,
            'replier_name' => $post->user->name,
        ];

        $this->send($author, Notification::TYPE_FORUM_REPLY, $title, $message, $data);

        // Notifier aussi les participants du topic (qui ont posté)
        $participants = User::query()
            ->whereHas('forumPosts', function ($query) use ($topic) {
                $query->where('topic_id', $topic->id);
            })
            ->where('id', '!=', $author->id) // Pas l'auteur du topic
            ->where('id', '!=', $post->user_id) // Pas l'auteur de ce post
            ->get();

        if ($participants->isNotEmpty()) {
            $participantTitle = "Nouvelle réponse";
            $participantMessage = "{$post->user->name} a répondu au topic \"{$topic->title}\".";

            return 1 + $this->sendToMany($participants, Notification::TYPE_FORUM_REPLY, $participantTitle, $participantMessage, $data);
        }

        return 1;
    }

    /**
     * Notifier qu'un post a été marqué comme solution
     */
    public function notifyForumSolution(ForumPost $post): int
    {
        $topic = $post->topic;
        $postAuthor = $post->user;

        // Notifier l'auteur du post (solution)
        $title = "Votre réponse a été marquée comme solution";
        $message = "Votre réponse au topic \"{$topic->title}\" a été acceptée comme solution !";
        $data = [
            'topic_id' => $topic->id,
            'post_id' => $post->id,
            'topic_title' => $topic->title,
        ];

        return $this->send($postAuthor, Notification::TYPE_FORUM_SOLUTION, $title, $message, $data) ? 1 : 0;
    }

    /**
     * Notifier la disponibilité d'un nouveau quiz
     */
    public function notifyQuizAvailable(Quiz $quiz): int
    {
        // Récupérer les étudiants de la classe
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

        return $this->sendToMany($students, Notification::TYPE_QUIZ_AVAILABLE, $title, $message, $data);
    }

    /**
     * Notifier la réception d'une note de quiz
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

        return $this->send($student, Notification::TYPE_GRADE_RECEIVED, $title, $message, $data) ? 1 : 0;
    }

    /**
     * Notifier la date limite d'un quiz
     */
    public function notifyQuizDeadline(Quiz $quiz, int $daysRemaining = 1): int
    {
        // Récupérer les étudiants qui n'ont pas encore passé le quiz
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

        return $this->sendToMany($students, Notification::TYPE_QUIZ_DEADLINE, $title, $message, $data);
    }

    /**
     * Supprimer les anciennes notifications (cleanup)
     *
     * @param int $days Nombre de jours à conserver (défaut: 30)
     */
    public function cleanupOldNotifications(int $days = 30): int
    {
        return Notification::query()
            ->where('created_at', '<', now()->subDays($days))
            ->whereNotNull('read_at') // Supprimer seulement les notifications lues
            ->delete();
    }
}
