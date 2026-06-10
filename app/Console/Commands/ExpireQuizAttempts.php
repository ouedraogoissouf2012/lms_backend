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

    /**
     * Execute the console command.
     */
    public function handle(QuizAttemptTimerService $timer, QuizGradingService $grading): int
    {
        $this->info('Recherche des tentatives de quiz expirées...');

        // Récupérer toutes les tentatives en cours
        $attempts = QuizAttempt::with('quiz')
            ->inProgress()
            ->whereHas('quiz', function ($query) {
                $query->whereNotNull('duration_minutes');
            })
            ->get();

        $expiredCount = 0;

        foreach ($attempts as $attempt) {
            if ($timer->hasExpired($attempt)) {
                $this->warn("Tentative #{$attempt->id} expirée pour l'utilisateur #{$attempt->user_id}");

                // Soumettre automatiquement avec les réponses sauvegardées
                $grading->submitAttempt($attempt, $attempt->answers ?? []);

                $expiredCount++;
            }
        }

        if ($expiredCount > 0) {
            $this->info("✓ {$expiredCount} tentative(s) expirée(s) et soumise(s) automatiquement.");
        } else {
            $this->info('✓ Aucune tentative expirée trouvée.');
        }

        return Command::SUCCESS;
    }
}
