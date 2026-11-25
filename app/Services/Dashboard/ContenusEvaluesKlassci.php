<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Volet KLASSCI : l'évaluation porte son auteur local (`enseignant_id`, D72) et, avant D72 ou
 * synchronisée, son identité KLASSCI (`klassci_enseignant_id`). Sa classe et sa matière sont des
 * identifiants KLASSCI, RAMENÉS ici à l'id LOCAL de leur miroir (`klassci_id`) dans la même
 * école : une classe KLASSCI sans miroir local n'est pas comptée, et un même nombre dans les deux
 * espaces ne désigne jamais la même classe.
 *
 * Trois requêtes, quel que soit le nombre d'évaluations : les évaluations, puis une
 * correspondance groupée (`whereIn`) par table.
 */
final class ContenusEvaluesKlassci implements ContenusEvaluesDeLEnseignant
{
    public function de(User $enseignant, int $ecole): array
    {
        $identite = is_numeric($enseignant->klassci_enseignant_id) ? (int) $enseignant->klassci_enseignant_id : null;

        $lignes = Evaluation::query()
            ->withoutGlobalScope('institution')
            ->where('institution_id', $ecole)
            ->where(static function (Builder $auteur) use ($enseignant, $identite): void {
                $auteur->where('enseignant_id', $enseignant->id);
                if ($identite !== null) {
                    $auteur->orWhere('klassci_enseignant_id', $identite);
                }
            })
            ->get(['classe_id', 'matiere_id', 'klassci_classe_id', 'klassci_matiere_id']);

        return [
            'evaluations' => $lignes->count(),
            'classes' => $this->locaux(Classe::query(), $ecole, $lignes->pluck('classe_id')->all(), $lignes->pluck('klassci_classe_id')->all()),
            'matieres' => $this->locaux(Matiere::query(), $ecole, $lignes->pluck('matiere_id')->all(), $lignes->pluck('klassci_matiere_id')->all()),
        ];
    }

    /**
     * Les ids locaux déjà posés, et ceux des miroirs des identifiants KLASSCI.
     *
     * @param  Builder<Classe>|Builder<Matiere>  $miroir
     * @param  array<mixed>  $locaux
     * @param  array<mixed>  $klassci
     * @return list<int>
     */
    private function locaux(Builder $miroir, int $ecole, array $locaux, array $klassci): array
    {
        $aRamener = IdsLocaux::de($klassci);
        $miroirs = $aRamener === [] ? [] : $miroir
            ->withoutGlobalScope('institution')
            ->where('institution_id', $ecole)
            ->whereIn('klassci_id', $aRamener)
            ->pluck('id')
            ->all();

        return IdsLocaux::de([...$locaux, ...$aRamener]);
    }
}
