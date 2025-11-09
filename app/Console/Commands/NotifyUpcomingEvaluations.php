<?php

namespace App\Console\Commands;

use App\Models\Evaluation;
use App\Models\Notification;
use App\Services\KlassciProxyService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NotifyUpcomingEvaluations extends Command
{
    protected $signature = 'evaluations:notify-upcoming {--hours=24}';
    protected $description = 'Envoie des notifications aux étudiants pour les évaluations approchantes';
    protected $klassciService;

    public function __construct(KlassciProxyService $klassciService)
    {
        parent::__construct();
        $this->klassciService = $klassciService;
    }

    public function handle()
    {
        $hoursBeforeNotification = (int) $this->option('hours');
        $this->info("Recherche des évaluations dans les prochaines {$hoursBeforeNotification} heures...");

        $now = Carbon::now();
        $targetTime = $now->copy()->addHours($hoursBeforeNotification);

        $evaluations = Evaluation::where('is_published', true)
            ->where('status', '!=', 'terminee')
            ->whereBetween('date_evaluation', [$now, $targetTime])
            ->get();

        if ($evaluations->isEmpty()) {
            $this->info('Aucune évaluation approchante trouvée.');
            return 0;
        }

        $this->info("{$evaluations->count()} évaluation(s) approchante(s) trouvée(s).");
        $notificationsCreated = 0;

        foreach ($evaluations as $evaluation) {
            $this->line("Traitement: {$evaluation->titre}");

            try {
                $etudiants = $this->klassciService->getClasseEtudiants($evaluation->klassci_classe_id);

                if (!isset($etudiants['data']) || empty($etudiants['data'])) {
                    $this->warn("Aucun étudiant trouvé");
                    continue;
                }

                foreach ($etudiants['data'] as $etudiant) {
                    $existingNotification = Notification::where('user_id', $etudiant['id'])
                        ->where('type', 'evaluation_approaching')
                        ->where('data->evaluation_id', $evaluation->id)
                        ->whereDate('created_at', Carbon::today())
                        ->first();

                    if ($existingNotification) {
                        continue;
                    }

                    Notification::create([
                        'user_id' => $etudiant['id'],
                        'type' => 'evaluation_approaching',
                        'title' => 'Évaluation approchante',
                        'message' => "L'évaluation \"{$evaluation->titre}\" aura lieu le " .
                                    Carbon::parse($evaluation->date_evaluation)->format('d/m/Y à H:i'),
                        'data' => [
                            'evaluation_id' => $evaluation->id,
                            'evaluation_titre' => $evaluation->titre,
                            'date_evaluation' => $evaluation->date_evaluation,
                        ],

                    ]);

                    $notificationsCreated++;
                }

            } catch (\Exception $e) {
                $this->error("Erreur: {$e->getMessage()}");
                continue;
            }
        }

        $this->info("{$notificationsCreated} notification(s) créée(s)!");
        return 0;
    }
}
