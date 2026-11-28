<?php

declare(strict_types=1);

namespace App\Services\EmploiDuTemps;

use App\Models\Classe;
use App\Models\EmploiDuTemps;
use App\Models\LigneEmploiDuTemps;
use App\Models\Matiere;
use App\Models\Seance;
use App\Models\User;
use App\Services\Catalogue\ClasseArchivee;
use App\Services\Seances\Conflits\ConflitsDHoraire;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * La génération des séances datées d'un emploi du temps (#1669, ARRÊT 1 validé le 28/11) :
 * - de max(`du`, aujourd'hui) à `au`, une séance par ligne de COURS et par jour de la semaine qui lui correspond ;
 *   une séance dont le début est déjà passé n'est pas créée ;
 * - la séance appartient à l'ENSEIGNANT de la ligne (`created_by`, comme le lit `teacherOwns`, Q3) ; elle porte les
 *   mêmes attributs qu'une séance créée à l'écran (`LocalSeanceCreator`), et le lien vers sa ligne ;
 * - idempotente : une séance déjà générée (ligne, date) est ignorée (`deja_generee`) — l'unicité en base le garantit ;
 *   une séance générée puis SUPPRIMÉE (suppression douce) compte aussi : un rejeu ne ressuscite jamais ce qu'un
 *   enseignant a retiré ;
 * - chaque séance passe par {@see ConflitsDHoraire} : en conflit, elle est IGNORÉE et rapportée (`conflit`), jamais
 *   forcée (exigence de la superviseure) ; une ligne sans enseignant est ignorée (`sans_enseignant`).
 * Une classe ARCHIVÉE (#1763) ne génère rien : 409 `class_archived`.
 * Les jours fermés du calendrier arrivent avec #1671.
 */
final class GenerationDesSeances
{
    public function __construct(
        private readonly ConflitsDHoraire $conflits,
        private readonly ConnectionInterface $db,
    ) {}

    /** @return array{creees: int, ignorees: list<array{date: string, ligne_id: int, motif: string, conflits: list<int>}>} */
    public function generer(EmploiDuTemps $emploi): array
    {
        $cours = $emploi->lignes->filter(static fn (LigneEmploiDuTemps $l): bool => $l->estUnCours())->values();
        $rapport = ['creees' => 0, 'ignorees' => []];
        $classe = Classe::query()->withoutGlobalScope('institution')->whereKey($emploi->classe_id)->first();
        if ($classe?->estArchivee() === true) {
            throw ClasseArchivee::exception();
        }
        $debut = CarbonImmutable::parse($emploi->du)->max(CarbonImmutable::today());
        for ($jour = $debut; $jour->lte(CarbonImmutable::parse($emploi->au)); $jour = $jour->addDay()) {
            foreach ($cours->where('jour', $jour->dayOfWeekIso) as $ligne) {
                $issue = $this->db->transaction(fn (): IssueDeGeneration => $this->uneSeance($emploi, $classe, $ligne, $jour));
                if (! $issue instanceof IssueDeGeneration) {
                    throw new LogicException('La génération d\'une séance n\'a rien retenu.');
                }
                if ($issue->creee) {
                    $rapport['creees']++;
                } elseif ($issue->motif !== null) {
                    $rapport['ignorees'][] = ['date' => $jour->toDateString(), 'ligne_id' => (int) $ligne->id, 'motif' => $issue->motif, 'conflits' => $issue->conflits];
                }
            }
        }

        return $rapport;
    }

    private function uneSeance(EmploiDuTemps $emploi, ?Classe $classe, LigneEmploiDuTemps $ligne, CarbonImmutable $jour): IssueDeGeneration
    {
        $debut = $jour->setTimeFromTimeString($ligne->debut);
        $fin = $jour->setTimeFromTimeString($ligne->fin);
        if ($debut->lte(now())) {
            return IssueDeGeneration::passee();
        }
        if (Seance::query()->withoutGlobalScopes()->where('emploi_du_temps_ligne_id', $ligne->id)->where('date_seance', $debut)->exists()) {
            return IssueDeGeneration::ignoree('deja_generee');
        }
        $enseignant = $ligne->enseignant_id === null ? null
            : User::query()->withoutGlobalScope('institution')->where('institution_id', $emploi->institution_id)->whereKey($ligne->enseignant_id)->first();
        if (! $enseignant instanceof User) {
            return IssueDeGeneration::ignoree('sans_enseignant');
        }
        $creneau = $this->conflits->creneau($emploi->institution_id, $debut, $fin, $enseignant, $emploi->classe_id);
        $this->conflits->verrouiller($creneau);
        $conflits = $this->conflits->trouver($creneau);
        if ($conflits !== []) {
            return IssueDeGeneration::ignoree('conflit', array_map(static fn (array $c): int => (int) $c['id'], $conflits));
        }
        Seance::query()->create($this->attributs($emploi, $classe, $ligne, $enseignant, $debut, $fin));

        return IssueDeGeneration::creee();
    }

    /** @return array<string, mixed> les attributs d'une séance créée à l'écran, et le lien vers la ligne */
    private function attributs(EmploiDuTemps $emploi, ?Classe $classe, LigneEmploiDuTemps $ligne, User $enseignant, CarbonImmutable $debut, CarbonImmutable $fin): array
    {
        $matiere = Matiere::query()->withoutGlobalScope('institution')->whereKey($ligne->matiere_id)->first();

        return [
            'titre' => $matiere->libelle ?? '',
            'date_seance' => $debut->toDateTimeString(),
            'fin_seance' => $fin->toDateTimeString(),
            'type_seance' => $ligne->type_seance,
            'matiere_id' => $ligne->matiere_id,
            'matiere_nom' => $matiere?->libelle,
            'classe_nom' => $classe?->libelle,
            'classe_id' => $emploi->classe_id,
            'emploi_du_temps_ligne_id' => $ligne->id,
            'created_by' => $enseignant->id,
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_matiere_id' => null,
            'klassci_classe_id' => null,
            'is_active' => true,
            'institution_id' => $emploi->institution_id,
        ];
    }
}
