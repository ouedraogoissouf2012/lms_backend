<?php

namespace App\Console\Commands;

use App\Services\Notification\RappelDEvaluation;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Le rappel du matin, planifié dans `routes/console.php`. La règle vit dans
 * {@see RappelDEvaluation} (#1247) : les élèves qui voient l'évaluation, sans KLASSCI.
 */
class NotifyUpcomingEvaluations extends Command
{
    protected $signature = 'evaluations:notify-upcoming {--hours=24}';

    protected $description = 'Envoie des notifications aux étudiants pour les évaluations approchantes';

    public function handle(RappelDEvaluation $rappel): int
    {
        $hoursBeforeNotification = (int) $this->option('hours');
        $this->info("Recherche des évaluations dans les prochaines {$hoursBeforeNotification} heures...");

        $bilan = $rappel->rappeler(Carbon::now(), $hoursBeforeNotification);

        // La sortie console n'écrit que l'évaluation ; l'exception va au journal.
        foreach ($bilan->evaluationsEnEchec as $evaluationId) {
            $this->error("Erreur pendant le traitement de l'évaluation {$evaluationId}.");
        }

        $this->info("{$bilan->evaluations} évaluation(s) approchante(s) trouvée(s).");
        $this->info("{$bilan->rappels} notification(s) créée(s)!");

        return Command::SUCCESS;
    }
}
