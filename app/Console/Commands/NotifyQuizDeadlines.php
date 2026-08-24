<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Quiz;
use App\Services\Notification\QuizNotificationDispatcher;
use App\Services\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rappel d'échéance des quiz (#500).
 *
 * Dernier appelant manquant de la chaîne quiz : sans lui,
 * `QuizNotificationDispatcher::notifyQuizDeadline()` restait orphelin.
 *
 * ## Contexte tenant — pourquoi il est reposé explicitement
 *
 * Le cron n'a aucun tenant résolu. Sans restauration, les notifications
 * seraient écrites avec `institution_id = NULL` (`BelongsToInstitution`
 * journalise et laisse passer) puis masquées à la lecture HTTP par le scope
 * global : écrites, jamais vues. On reprend donc le pattern déjà éprouvé de
 * `DispatchLessonPublishedNotifications:76-88` — `reset()` puis `set()` par
 * institution. Le durcissement générique à la source est traité par #579 ;
 * cette commande est correcte sans en dépendre.
 *
 * ## Idempotence
 *
 * Un cron quotidien renotifierait indéfiniment. Même garde que
 * `NotifyUpcomingEvaluations:58-66` : un seul rappel par (étudiant, quiz) et
 * par jour.
 *
 * @see app/Services/Notification/QuizNotificationDispatcher.php
 * @see .claude/specs/500-notif-chain/design.md
 */
final class NotifyQuizDeadlines extends Command
{
    protected $signature = 'quiz:notify-deadlines {--days=1 : Fenêtre d\'anticipation en jours}';

    protected $description = 'Rappelle aux étudiants les quiz dont l\'échéance approche';

    public function handle(
        TenantManager $tenantManager,
        QuizNotificationDispatcher $notifications,
        LoggerInterface $logger,
    ): int {
        $days = max(1, (int) $this->option('days'));

        // Purge un tenant résiduel (worker persistant) avant le balayage global.
        $tenantManager->reset();

        $quizzes = $this->quizzesClosingWithin($days);

        if ($quizzes->isEmpty()) {
            $this->info('Aucun quiz dont l\'échéance approche.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        // Isolation par quiz : sans elle, une seule exception avorte le run et
        // AFFAME tous les quiz suivants — leur fenêtre de rappel se refermant
        // dans la journée, le rappel serait définitivement perdu. C'est la
        // même précaution que `DispatchLessonPublishedNotifications` et
        // `NotifyUpcomingEvaluations`.
        foreach ($quizzes as $quiz) {
            try {
                $sent += $this->remindFor($quiz, $tenantManager, $notifications);
            } catch (Throwable $e) {
                $failed++;
                $logger->error('Rappel d\'échéance quiz échoué', [
                    'quiz_id' => $quiz->id,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                // Ne jamais laisser fuir le tenant d'un quiz sur le suivant.
                $tenantManager->reset();
            }
        }

        $this->info("{$sent} rappel(s) envoyé(s) pour {$quizzes->count()} quiz ({$failed} en échec).");

        return self::SUCCESS;
    }

    /**
     * Quiz publiés dont l'échéance tombe dans la fenêtre.
     *
     * Balayage volontairement cross-tenant, déclaré explicitement plutôt que
     * de s'appuyer sur le fail-open du scope global (qui journalise un
     * avertissement à chaque requête sans tenant).
     *
     * @return Collection<int, Quiz>
     */
    private function quizzesClosingWithin(int $days): Collection
    {
        return Quiz::query()
            ->withoutGlobalScope('institution')
            ->whereNotNull('available_until')
            ->whereBetween('available_until', [now(), now()->addDays($days)])
            ->where('status', 'published')
            ->with('institution')
            ->get();
    }

    /**
     * Repose le tenant du quiz puis délègue le fan-out au dispatcher.
     */
    private function remindFor(
        Quiz $quiz,
        TenantManager $tenantManager,
        QuizNotificationDispatcher $notifications,
    ): int {
        $institution = $quiz->institution;

        if ($institution === null) {
            $this->warn("Quiz {$quiz->id} sans institution — ignoré.");

            return 0;
        }

        $tenantManager->set($institution);

        if ($this->alreadyRemindedToday($quiz)) {
            return 0;
        }

        return $notifications->notifyQuizDeadline($quiz, $this->daysRemainingFor($quiz));
    }

    /**
     * Délai RÉEL avant fermeture, arrondi au jour supérieur (minimum 1).
     *
     * `--days` est la fenêtre de BALAYAGE, pas le délai restant : la passer
     * telle quelle au message faisait annoncer « se termine dans 7 jours » à
     * un quiz fermant dans 30 minutes.
     */
    private function daysRemainingFor(Quiz $quiz): int
    {
        $closesAt = $quiz->available_until;

        if ($closesAt === null) {
            return 1;
        }

        return max(1, (int) ceil(now()->diffInHours($closesAt, absolute: true) / 24));
    }

    /**
     * Un rappel a-t-il déjà été émis aujourd'hui pour ce quiz ?
     *
     * La garde porte sur le quiz et non sur chaque étudiant : le dispatcher
     * fait le fan-out en une passe, donc un rappel du jour signifie que la
     * cohorte a déjà été traitée.
     */
    private function alreadyRemindedToday(Quiz $quiz): bool
    {
        // `whereRaw(json_extract(...))` et non `where('data->quiz_id', …)` :
        // c'est l'idiome déjà retenu par `VisioNotificationIdempotencyGuard:70`
        // (portable SQLite/MySQL) et il évite d'ajouter une entrée de baseline
        // PHPStan, la flèche JSON n'étant pas une propriété du modèle.
        return Notification::query()
            ->where('type', Notification::TYPE_QUIZ_DEADLINE)
            ->whereRaw("json_extract(data, '$.quiz_id') = ?", [$quiz->id])
            ->whereDate('created_at', now()->toDateString())
            ->exists();
    }
}
