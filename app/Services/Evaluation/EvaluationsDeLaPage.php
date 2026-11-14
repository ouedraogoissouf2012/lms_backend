<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Evaluation\Student\LearnerEvaluationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Les évaluations des fiches classe et matière d'une école créée depuis le LMS, et
 * leurs compteurs (D72, E4c). Une seule règle par rôle, partagée par les quatre
 * lecteurs locaux :
 *
 * | Appelant | Évaluations |
 * |---|---|
 * | Gestion | toutes celles de l'établissement |
 * | Enseignant | les SIENNES (`enseignant_id`), brouillons compris (D73, la règle de « Mes leçons ») |
 * | Élève, et tout autre rôle | sa liste ({@see LearnerEvaluationScope}, E2a), et sa copie (`ownedBy`) |
 *
 * Toujours bornées EXPLICITEMENT à l'établissement : le scope global est fail-open
 * sans tenant. Les compteurs comptent le même ensemble que la liste rendue.
 */
final class EvaluationsDeLaPage
{
    public function __construct(
        private readonly LearnerEvaluationScope $eleves,
        private readonly EvaluationProgrammeeLocale $forme,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function deLaClasse(Classe $classe, User $user): array
    {
        return $this->lignes($user, (int) $classe->institution_id, 'classe_id', (int) $classe->id, avecMatiere: true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function deLaMatiere(Matiere $matiere, User $user): array
    {
        return $this->lignes($user, (int) $matiere->institution_id, 'matiere_id', (int) $matiere->id, avecMatiere: false);
    }

    /**
     * Le nombre d'évaluations par matière, en UNE requête groupée : toutes celles de
     * l'établissement (gestion), ou celles d'un enseignant.
     *
     * @param  list<int>  $matiereIds
     * @return array<int, int> matiere_id => nombre
     */
    public static function compteParMatiere(int $etablissement, array $matiereIds, ?int $enseignant): array
    {
        if ($matiereIds === []) {
            return [];
        }

        $comptes = [];
        $lignes = Evaluation::query()
            ->where('institution_id', $etablissement)
            ->whereIn('matiere_id', $matiereIds)
            ->when($enseignant !== null, static fn (Builder $q) => $q->where('enseignant_id', $enseignant))
            ->groupBy('matiere_id')
            ->selectRaw('matiere_id, count(*) as nombre')
            ->toBase()
            ->get();
        foreach ($lignes as $ligne) {
            $comptes[(int) $ligne->matiere_id] = (int) $ligne->nombre;
        }

        return $comptes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lignes(User $user, int $etablissement, string $colonne, int $id, bool $avecMatiere): array
    {
        $ensemble = $this->ensemble($user, $etablissement);
        if ($ensemble === null) {
            return [];
        }

        $evaluations = $ensemble->where($ensemble->qualifyColumn($colonne), $id)
            ->withCount(['questions', 'submissions'])
            ->orderBy($ensemble->qualifyColumn('date_evaluation'))
            ->orderBy($ensemble->qualifyColumn('id'))
            ->get();
        $copies = $this->ouvreLaListeEleve($user) ? $this->copiesDe($user, $evaluations) : [];
        $matieres = $avecMatiere ? $this->matieresDe($evaluations, $etablissement) : [];

        return array_values($evaluations->map(fn (Evaluation $evaluation): array => $this->forme->locale(
            $evaluation,
            $copies[$evaluation->id] ?? null,
            $avecMatiere ? ($matieres[(int) $evaluation->matiere_id] ?? null) : null,
        ))->all());
    }

    /**
     * @return Builder<Evaluation>|null
     */
    private function ensemble(User $user, int $etablissement): ?Builder
    {
        if ($user->isManager()) {
            return Evaluation::query()->where('institution_id', $etablissement);
        }

        if ($user->isTeacher()) {
            return Evaluation::query()->where('institution_id', $etablissement)->where('enseignant_id', $user->id);
        }

        $visibles = $this->eleves->visibleFor($user);

        return $visibles?->where($visibles->qualifyColumn('institution_id'), $etablissement);
    }

    private function ouvreLaListeEleve(User $user): bool
    {
        return ! $user->isManager() && ! $user->isTeacher();
    }

    /**
     * La dernière copie de l'élève par évaluation, en une requête.
     *
     * @param  Collection<int, Evaluation>  $evaluations
     * @return array<int, EvaluationSubmission>
     */
    private function copiesDe(User $user, Collection $evaluations): array
    {
        if ($evaluations->isEmpty()) {
            return [];
        }

        $copies = [];
        $lignes = EvaluationSubmission::query()
            ->whereIn('evaluation_id', $evaluations->modelKeys())
            ->ownedBy($user)
            ->orderBy('id')
            ->get();
        foreach ($lignes as $copie) {
            $copies[(int) $copie->evaluation_id] = $copie;
        }

        return $copies;
    }

    /**
     * @param  Collection<int, Evaluation>  $evaluations
     * @return array<int, Matiere>
     */
    private function matieresDe(Collection $evaluations, int $etablissement): array
    {
        $ids = [];
        foreach ($evaluations as $evaluation) {
            if (is_numeric($evaluation->matiere_id)) {
                $ids[(int) $evaluation->matiere_id] = (int) $evaluation->matiere_id;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            return [];
        }

        return Matiere::query()->where('institution_id', $etablissement)->whereKey($ids)->get()->keyBy('id')->all();
    }
}
