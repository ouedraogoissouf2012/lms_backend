<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\Notification;
use App\Models\User;
use App\Services\Audience\ElevesDeLaClasse;
use App\Services\Evaluation\ClasseDeLEvaluation;
use App\Services\Evaluation\KlassciClasseDeLEvaluation;
use App\Services\Evaluation\LocalClasseDeLEvaluation;
use App\Services\Evaluation\Student\EvaluationsOuvertesAuxEleves;
use App\Services\Roster\RosterAuthorityFactory;
use App\Support\Logging\CaughtExceptionLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Le rappel du matin (#1247) : les élèves qui VOIENT une évaluation à venir en sont avertis,
 * une fois par jour. Aucun appel à KLASSCI, dans aucune école.
 *
 * - Les évaluations : ce que l'élève voit ({@see EvaluationsOuvertesAuxEleves}), planifiées
 *   ou en cours, dans la fenêtre.
 * - Leurs élèves : la classe locale de l'évaluation ({@see ClasseDeLEvaluation}, par le mode
 *   de son école), puis la règle partagée {@see ElevesDeLaClasse}. Ce sont des comptes LOCAUX
 *   de l'école : un élève KLASSCI sans compte LMS ne peut lire aucune notification, il n'est
 *   ni cherché ni deviné.
 * - L'établissement est posé sur chaque ligne : en console, sans tenant, le crochet de
 *   `BelongsToInstitution` le laisserait nul et le rappel serait invisible (#579).
 *
 * Une évaluation en échec est journalisée et n'arrête pas les suivantes.
 */
final class RappelDEvaluation
{
    public function __construct(
        private readonly RosterAuthorityFactory $autorites,
        private readonly ElevesDeLaClasse $eleves,
        private readonly LoggerInterface $logger,
        private readonly CaughtExceptionLog $journal,
    ) {}

    public function rappeler(CarbonInterface $maintenant, int $heures): BilanDuRappel
    {
        $evaluations = 0;
        $rappels = 0;
        $echecs = [];

        foreach (Institution::query()->orderBy('id')->get() as $ecole) {
            foreach ($this->aRappeler($ecole, $maintenant, $heures) as $evaluation) {
                $evaluations++;
                try {
                    $rappels += $this->avertir($ecole, $evaluation, $maintenant);
                } catch (Throwable $e) {
                    $echecs[] = $evaluation->id;
                    $this->journal->error('Rappel d\'évaluation interrompu', $e, ['evaluation_id' => $evaluation->id]);
                }
            }
        }

        return new BilanDuRappel($evaluations, $rappels, $echecs);
    }

    /** @return Collection<int, Evaluation> */
    private function aRappeler(Institution $ecole, CarbonInterface $maintenant, int $heures): Collection
    {
        return EvaluationsOuvertesAuxEleves::de($ecole->id)
            ->withoutGlobalScope('institution')
            ->whereIn('status', [EvaluationStatus::Planifiee->value, EvaluationStatus::EnCours->value])
            ->whereBetween('date_evaluation', [$maintenant, $maintenant->copy()->addHours($heures)])
            ->orderBy('id')
            ->get();
    }

    /** @return int le nombre d'élèves avertis */
    private function avertir(Institution $ecole, Evaluation $evaluation, CarbonInterface $maintenant): int
    {
        $classe = $this->autorites
            ->sourceForInstitution($ecole, KlassciClasseDeLEvaluation::class, LocalClasseDeLEvaluation::class)
            ->de($evaluation);
        if ($classe === null) {
            $this->logger->info('Rappel d\'évaluation : aucune classe locale, personne à avertir', ['evaluation_id' => $evaluation->id]);

            return 0;
        }

        $eleves = app(\App\Services\Enrollment\EnrollmentSource::class)->localStudentIdsOf($classe);
        if ($eleves === []) {
            $this->logger->info('Rappel d\'évaluation : aucun élève à avertir dans la classe', [
                'evaluation_id' => $evaluation->id,
                'classe_id' => $classe->id,
            ]);

            return 0;
        }

        $aAvertir = array_values(array_diff($eleves, $this->dejaAvertis($ecole, $evaluation, $eleves, $maintenant)));
        if ($aAvertir !== []) {
            Notification::insert($this->lignes($ecole, $evaluation, $aAvertir, $maintenant));
        }

        $this->logger->info('Rappel d\'évaluation envoyé', [
            'evaluation_id' => $evaluation->id,
            'avertis' => count($aAvertir),
            'deja_avertis' => count($eleves) - count($aAvertir),
        ]);

        return count($aAvertir);
    }

    /**
     * Les élèves déjà avertis de cette évaluation aujourd'hui, en UNE requête.
     *
     * @param  list<int>  $eleves
     * @return list<int>
     */
    private function dejaAvertis(Institution $ecole, Evaluation $evaluation, array $eleves, CarbonInterface $maintenant): array
    {
        $deja = [];
        $lignes = Notification::query()
            ->withoutGlobalScope('institution')
            ->where('institution_id', $ecole->id)
            ->where('type', Notification::TYPE_EVALUATION_APPROACHING)
            // La clé JSON comme la garde des avertissements visio la lit, sur les deux moteurs.
            ->whereRaw("json_extract(data, '$.evaluation_id') = ?", [$evaluation->id])
            ->whereDate('created_at', $maintenant->toDateString())
            ->whereIn('user_id', $eleves)
            ->pluck('user_id');
        foreach ($lignes as $id) {
            if (is_numeric($id)) {
                $deja[] = (int) $id;
            }
        }

        return $deja;
    }

    /**
     * Le titre, le message et les données d'avant #1247 : le front les affiche tels quels.
     *
     * @param  list<int>  $eleves
     * @return list<array<string, mixed>>
     */
    private function lignes(Institution $ecole, Evaluation $evaluation, array $eleves, CarbonInterface $maintenant): array
    {
        $donnees = json_encode([
            'evaluation_id' => $evaluation->id,
            'evaluation_titre' => $evaluation->titre,
            'date_evaluation' => $evaluation->date_evaluation,
        ], JSON_THROW_ON_ERROR);
        $message = "L'évaluation \"{$evaluation->titre}\" aura lieu le "
            .$evaluation->date_evaluation?->format('d/m/Y à H:i');

        return array_map(static fn (int $eleve): array => [
            'user_id' => $eleve,
            'institution_id' => $ecole->id,
            'type' => Notification::TYPE_EVALUATION_APPROACHING,
            'title' => 'Évaluation approchante',
            'message' => $message,
            'data' => $donnees,
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ], $eleves);
    }
}
