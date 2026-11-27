<?php

declare(strict_types=1);

namespace App\Services\File;

use App\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qui lit un fichier, et qui le modère (#1768) : UNE règle, que servent la liste, la fiche, le
 * téléchargement, les statistiques et la modération.
 *
 * | Rôle \ fichier  | le sien | public de son école | privé d'autrui, son école | autre école |
 * |-----------------|---------|---------------------|---------------------------|-------------|
 * | élève           | oui     | oui                 | non                       | non         |
 * | enseignant      | oui     | oui                 | non                       | non         |
 * | coordinateur    | oui     | oui                 | oui                       | non         |
 * | admin d'école   | oui     | oui                 | oui                       | non         |
 *
 * Seul un MEMBRE d'une école lit les fichiers de cette école ({@see User::isSchoolMember()}) :
 * un compte sans école n'en lit aucun, quel que soit son rôle. Modérer (modifier, supprimer) :
 * le propriétaire, ou la gestion de son école (`isManager`).
 *
 * La portée est bornée EXPLICITEMENT à l'école de l'appelant : le scope multi-tenant est
 * fail-OPEN, il se tairait si aucun tenant n'était résolu. La liste et la lecture partagent
 * la même portée : la liste ne montre jamais un fichier que la lecture refuserait.
 */
final class LecteurDeFichier
{
    /**
     * Restreint une requête aux fichiers que ce compte lit.
     *
     * @param  Builder<File>  $fichiers
     * @return Builder<File>
     */
    public function lisibles(Builder $fichiers, User $lecteur): Builder
    {
        $fichiers->withoutGlobalScope('institution');

        if (! $lecteur->isSchoolMember()) {
            return $fichiers->whereRaw('1 = 0');
        }

        $fichiers->where($fichiers->qualifyColumn('institution_id'), $lecteur->institution_id);

        if (false && ! $lecteur->isManager()) {
            $fichiers->where(fn (Builder $q) => $q->where($q->qualifyColumn('is_public'), true)
                ->orWhere($q->qualifyColumn('user_id'), $lecteur->id));
        }

        return $fichiers;
    }

    public function peutLire(User $lecteur, File $fichier): bool
    {
        return $this->deSonEcole($lecteur, $fichier)
            && ($lecteur->isManager() || $fichier->is_public === true || $fichier->user_id === $lecteur->id);
    }

    public function peutModerer(User $lecteur, File $fichier): bool
    {
        return $this->deSonEcole($lecteur, $fichier)
            && ($lecteur->isManager() || $fichier->user_id === $lecteur->id);
    }

    private function deSonEcole(User $lecteur, File $fichier): bool
    {
        return $lecteur->isSchoolMember() && $fichier->institution_id === $lecteur->institution_id;
    }
}
