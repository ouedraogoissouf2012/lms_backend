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

    /**
     * Une semaine. Le rappel est quotidien et ne se répète qu'une fois par jour : une fenêtre
     * de N heures avertit chaque élève jusqu'à ⌈N/24⌉ fois pour la même évaluation. Au-delà,
     * « approchante » ne veut plus rien dire.
     */
    private const HEURES_MAX = 168;

    public function handle(RappelDEvaluation $rappel): int
    {
        // Refuser, pas lire avec un défaut : `(int)` faisait de `abc` une fenêtre vide et un
        // SUCCESS, de `5abc` une fenêtre de 5 heures (#1429). Rien n'est envoyé.
        $saisie = $this->option('hours');
        $hoursBeforeNotification = filter_var($saisie, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::HEURES_MAX]]);
        if ($hoursBeforeNotification === false) {
            $this->error(sprintf('--hours attend un entier de 1 à %d, reçu : « %s ».', self::HEURES_MAX, is_string($saisie) ? $saisie : ''));

            return Command::FAILURE;
        }

        $this->info("Recherche des évaluations dans les prochaines {$hoursBeforeNotification} heures...");

        $bilan = $rappel->rappeler(Carbon::now(), $hoursBeforeNotification);

        // La sortie console n'écrit que l'évaluation ; l'exception va au journal.
        foreach ($bilan->evaluationsEnEchec as $evaluationId) {
            $this->error("Erreur pendant le traitement de l'évaluation {$evaluationId}.");
        }

        $this->info("{$bilan->evaluations} évaluation(s) approchante(s) trouvée(s).");
        $this->info("{$bilan->rappels} notification(s) créée(s)!");

        // #1444 : un rappel en échec fait échouer la commande, sans annuler les autres, déjà
        // écrits. Le planificateur voit l'échec ; seuls les id sont nommés.
        if ($bilan->evaluationsEnEchec !== []) {
            $this->error(sprintf(
                '%d rappel(s) en échec : évaluation(s) %s.',
                count($bilan->evaluationsEnEchec),
                implode(', ', $bilan->evaluationsEnEchec),
            ));

            return Command::SUCCESS; // F1 (falsification #1444) : succès malgré l'échec
        }

        return Command::SUCCESS;
    }
}
