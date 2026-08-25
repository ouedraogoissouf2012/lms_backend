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
    protected KlassciProxyService $klassciService;

    public function __construct(KlassciProxyService $klassciService)
    {
        parent::__construct();
        $this->klassciService = $klassciService;
    }

    public function handle(): int
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
            return Command::SUCCESS;
        }

        $this->info("{$evaluations->count()} évaluation(s) approchante(s) trouvée(s).");
        $notificationsCreated = 0;

        foreach ($evaluations as $evaluation) {
            $this->line("Traitement: {$evaluation->titre}");

            try {
                $etudiants = $this->klassciService->getClasseEtudiants($evaluation->klassci_classe_id);

                $students = self::extractStudents($etudiants);

                if ($students === []) {
                    $this->warn("Aucun étudiant trouvé");
                    continue;
                }

                foreach ($students as $studentId) {
                    $existingNotification = Notification::where('user_id', $studentId)
                        ->where('type', 'evaluation_approaching')
                        ->where('data->evaluation_id', $evaluation->id)
                        ->whereDate('created_at', Carbon::today())
                        ->first();

                    if ($existingNotification) {
                        continue;
                    }

                    Notification::create([
                        'user_id' => $studentId,
                        'type' => 'evaluation_approaching',
                        'title' => 'Évaluation approchante',
                        'message' => "L'évaluation \"{$evaluation->titre}\" aura lieu le " .
                                    Carbon::parse($evaluation->date_evaluation)->format('d/m/Y à H:i'),
                        'data' => [
                            'evaluation_id' => $evaluation->id,
                            'evaluation_titre' => $evaluation->titre,
                            'date_evaluation' => $evaluation->date_evaluation,
                        ],
                        // #579 — `institution_id` VOLONTAIREMENT non renseigné ici,
                        // contrairement à NotificationDispatcher::send().
                        //
                        // `$studentId` vient de `extractStudents()`, qui lit
                        // `data[].id` du payload KLASSCI : c'est un id KLASSCI,
                        // PAS un `users.id` local. La traduction que fait
                        // `DispatchLessonPublishedNotifications:102`
                        // (`User::whereIn('klassci_id', …)->pluck('id')`) manque
                        // ici, et `TeacherEvaluationResultsService:161` confirme
                        // la sémantique KLASSCI de ce champ.
                        //
                        // Ces notifications sont donc déjà mal adressées. Tant
                        // que `institution_id` reste NULL, le scope global les
                        // rend inertes. Y poser une institution les rendrait
                        // VISIBLES à l'utilisateur local qui porte par hasard
                        // cet identifiant — éventuellement d'une autre
                        // institution. On ne répare pas la visibilité d'une
                        // ligne mal adressée : il faut d'abord corriger
                        // l'adressage (issue de suivi).
                    ]);

                    $notificationsCreated++;
                }

            } catch (\Exception $e) {
                $this->error("Erreur: {$e->getMessage()}");
                continue;
            }
        }

        $this->info("{$notificationsCreated} notification(s) créée(s)!");
        return Command::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    private static function extractStudents(array $payload): array
    {
        if (! isset($payload['data']) || ! is_array($payload['data'])) {
            return [];
        }

        $studentIds = [];

        foreach ($payload['data'] as $student) {
            if (! is_array($student) || ! isset($student['id'])) {
                continue;
            }

            if (is_int($student['id']) || is_string($student['id']) && ctype_digit($student['id'])) {
                $studentIds[] = (int) $student['id'];
            }
        }

        return $studentIds;
    }
}
