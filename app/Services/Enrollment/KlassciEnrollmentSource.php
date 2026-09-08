<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\Classe;
use App\Models\Matiere;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Support\Facades\DB;

/**
 * Cache de sync KLASSCI : `user_classes.klassci_classe_id` ↔ `classes.klassci_id`.
 */
final class KlassciEnrollmentSource implements EnrollmentSource
{
    public function localClasseIdsFor(User $user): array
    {
        $klassciClasseIds = UserClass::query()
            ->where('user_id', $user->id)
            ->when(
                $user->institution_id !== null,
                fn ($query) => $query->where('institution_id', $user->institution_id)
            )
            ->pluck('klassci_classe_id');

        if ($klassciClasseIds->isEmpty()) {
            return [];
        }

        /** @var list<int> $localIds */
        $localIds = Classe::query()
            ->when(
                $user->institution_id !== null,
                fn ($query) => $query->where('institution_id', $user->institution_id)
            )
            ->whereIn('klassci_id', $klassciClasseIds)
            ->pluck('id')
            ->all();

        return $localIds;
    }

    /**
     * Les classes de l'enseignant, depuis le CACHE KLASSCI du lien
     * enseignant ↔ matière.
     *
     * ## Ce que cette méthode faisait, et pourquoi c'était mort
     *
     * Elle était un copier-coller de {@see LocalEnrollmentSource} : elle
     * interrogeait `classe_matiere.enseignant_id`, exactement comme la source
     * locale dont elle est censée être le REPLI. Deux chemins, une seule
     * requête — et cette colonne n'est écrite par personne dans le dépôt, ses
     * deux seules occurrences étant ces deux lectures.
     *
     * Mesure du 2026-09-08 : « Mes Classes » affichait « Aucune classe
     * assignée » pendant que le tableau de bord annonçait 4 classes.
     *
     * ## Ce qu'elle fait maintenant
     *
     * Elle honore le docblock de sa propre classe — « cache de sync KLASSCI » —
     * comme le fait déjà `localClasseIdsFor()` avec `user_classes`. Le cache du
     * lien enseignant ↔ matière est `matiere_enseignant`, alimenté à chaque
     * connexion par {@see TeacherMatieresLinker}.
     *
     * Le chemin : enseignant → ses matières KLASSCI → les matières locales
     * correspondantes → les classes qui les portent.
     *
     * ## Deux bornages, non négociables
     *
     * `klassci_enseignant_id` n'est unique QUE par institution, et
     * `klassci_id` de matière non plus (#707) : chaque étape est bornée à
     * l'établissement. Et un lien `inactive` ne compte pas — la colonne
     * `status` existe pour ça.
     *
     * @return list<int>
     */
    public function classeIdsForTeacher(User $teacher): array
    {
        $klassciEnseignantId = $teacher->klassci_enseignant_id;

        if (! is_numeric($klassciEnseignantId) || $teacher->institution_id === null) {
            return [];
        }

        $klassciMatiereIds = DB::table('matiere_enseignant')
            ->where('klassci_enseignant_id', (int) $klassciEnseignantId)
            ->where('institution_id', $teacher->institution_id)
            ->where('status', 'active')
            ->pluck('klassci_matiere_id');

        if ($klassciMatiereIds->isEmpty()) {
            return [];
        }

        $matiereIds = Matiere::query()
            ->where('institution_id', $teacher->institution_id)
            ->whereIn('klassci_id', $klassciMatiereIds)
            ->pluck('id');

        if ($matiereIds->isEmpty()) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = Classe::query()
            ->where('institution_id', $teacher->institution_id)
            ->whereHas(
                'matieres',
                static fn ($query) => $query->whereIn('matieres.id', $matiereIds),
            )
            ->pluck('id')
            ->all();

        return $ids;
    }
}
