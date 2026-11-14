<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Teacher;

use App\Models\EvaluationSubmission;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Le nom de l'élève de chaque copie, pour l'enseignant (D72, E3).
 *
 * Deux requêtes pour toute la liste, et non une par copie : par son auteur
 * (`student_id`, seul présent dans une école créée depuis le LMS), puis, pour
 * une ligne d'avant D72, par son identité KLASSCI. Même ordre que
 * {@see EvaluationSubmission::scopeOwnedBy()}.
 *
 * Les deux recherches sont bornées à l'établissement de l'évaluation : une
 * identité KLASSCI n'est unique qu'au sein d'une école, et le nom d'un compte
 * d'une autre école serait celui d'un tiers. Sans nom, « Étudiant #id ».
 *
 * Sert la liste des copies et les lignes des résultats hors roster.
 */
final class NomsDesAuteurs
{
    /**
     * @param  Collection<int, EvaluationSubmission>  $copies
     * @return \Closure(EvaluationSubmission): string
     */
    public static function de(Collection $copies, ?int $etablissement): \Closure
    {
        [$parAuteur, $parKlassci] = [[], []];

        // `where(col, null)` deviendrait `IS NULL` : un établissement inconnu
        // ne nomme personne.
        if ($etablissement !== null) {
            $parAuteur = User::query()
                ->where('institution_id', $etablissement)
                ->whereIn('id', $copies->pluck('student_id')->filter()->unique()->values())
                ->pluck('name', 'id')
                ->all();
            $parKlassci = User::query()
                ->where('institution_id', $etablissement)
                ->whereIn('klassci_id', $copies->pluck('klassci_etudiant_id')->filter()->unique()->values())
                ->pluck('name', 'klassci_id')
                ->all();
        }

        return static function (EvaluationSubmission $copie) use ($parAuteur, $parKlassci): string {
            $nom = $parAuteur[$copie->student_id ?? ''] ?? $parKlassci[$copie->klassci_etudiant_id ?? ''] ?? null;

            return is_string($nom) ? $nom : 'Étudiant #' . ($copie->klassci_etudiant_id ?? $copie->student_id);
        };
    }
}
