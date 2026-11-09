<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\Classe;
use App\Models\Matiere;
use Illuminate\Database\Eloquent\Builder;

/**
 * « Les classes qu'il enseigne », dans une école créée depuis le LMS : celles où
 * le compte est désigné formateur d'au moins une matière du programme
 * (`classe_matiere.enseignant_id`), d'une matière de l'établissement.
 *
 * UNE règle, pour « Mes classes » ({@see LocalTeacherClassesReader}) et pour la
 * grille d'accès de la fiche ({@see LocalClasseDetailsReader}). Écrite deux fois,
 * elle avait déjà divergé : la liste bornait la matière à l'établissement, la
 * fiche non. Les lecteurs des matières (lot 1) gardent leur propre copie, à
 * réunir ici quand un lot les touchera.
 *
 * Pas un scope du modèle : `Classe` est à la limite de taille des modèles.
 */
final class ClassesEnseignees
{
    /**
     * Le bornage de la matière passe par une sous-requête sur le modèle
     * `Matiere` : écrit `matieres.institution_id` dans la fermeture, il était
     * refusé par PHPStan, dont le schéma ne voit pas cette colonne sur `matieres`
     * (CI de #1001).
     *
     * @param  Builder<Classe>  $classes
     * @return Builder<Classe>
     */
    public static function par(Builder $classes, int $enseignantId, int $institution): Builder
    {
        return $classes->whereHas('matieres', static function ($programme) use ($enseignantId, $institution): void {
            $programme->where('classe_matiere.enseignant_id', $enseignantId)
                ->whereIn('classe_matiere.matiere_id', Matiere::query()
                    ->where('institution_id', $institution)
                    ->select('id'));
        });
    }
}
