<?php

namespace App\Console\Commands;

use App\Models\QuizAttempt;
use App\Services\Quiz\QuizAttemptTimerService;
use App\Services\Quiz\QuizGradingService;
use Illuminate\Console\Command;

/**
 * Expire automatiquement les tentatives de quiz dont le temps est écoulé
 * et les soumet avec les réponses sauvegardées.
 *
 * Fix audit H2 : l'ancien code appelait `$attempt->submit()` — méthode
 * supprimée du modèle en #176 (anti-pattern Service Locator). La commande
 * aurait crashé en runtime. Services injectés via method injection (pattern
 * Laravel officiel pour les commands).
 */
class ExpireQuizAttempts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'quiz:expire-attempts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire les tentatives de quiz dont le temps est écoulé et les soumet automatiquement';

    public int $drainBudgetSeconds = 45;

    public int $drainChunkSize = 200;

    /**
     * Execute the console command.
     */
    public function handle(QuizAttemptTimerService $timer, QuizGradingService $grading): int
    {
        $this->info('Recherche des tentatives de quiz expirées...');

        $startedAt = microtime(true);
        $expiredCount = 0;
        $budgetReached = false;

        QuizAttempt::query()
            ->with('quiz')
            ->inProgress()
            ->whereHas('quiz', function ($query): void {
                $query->whereNotNull('duration_minutes');
            })
            ->chunkById($this->drainChunkSize, function ($attempts) use ($timer, $grading, $startedAt, &$expiredCount, &$budgetReached): bool {
                foreach ($attempts as $attempt) {
                    if ($timer->hasExpired($attempt)) {
                        $grading->submitAttempt($attempt, $attempt->answers ?? []);
                        $expiredCount++;
                    }
                }

                if ((microtime(true) - $startedAt) >= $this->drainBudgetSeconds) {
                    $budgetReached = true;

                    return false;
                }

                return true;
            });

        $this->info($expiredCount > 0
            ? "✓ {$expiredCount} tentative(s) expirée(s) et soumise(s) automatiquement."
            : '✓ Aucune tentative expirée trouvée.');

        return Command::SUCCESS;
    }
}
