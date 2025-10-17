<?php

namespace App\Console\Commands;

use App\Models\QuizAttempt;
use Illuminate\Console\Command;

/**
 * Commande pour expirer automatiquement les tentatives de quiz dont le temps est écoulé
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
    public function handle(): int
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
            // Vérifier si la tentative a expiré
            if ($attempt->isTimeExpired()) {
                $this->warn("Tentative #{$attempt->id} expirée pour l'utilisateur #{$attempt->user_id}");

                // Soumettre automatiquement avec les réponses actuelles
                $attempt->submit($attempt->answers ?? []);

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
