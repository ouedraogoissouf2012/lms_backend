<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\Classe;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pivot local. Zéro HTTP. `user_classes` n'est plus lu.
 */
final class LocalEnrollmentSource implements EnrollmentSource
{
    /**
     * Le pivot actif, borné EXPLICITEMENT à l'établissement du compte : hors requête
     * (un job, une commande), la portée de tenant est inerte, et une ligne vers la
     * classe d'une autre école serait rendue. Sans établissement, aucune classe.
     */
    public function localClasseIdsFor(User $user): array
    {
        if (! is_int($user->institution_id)) {
            return [];
        }

        $classes = $user->classes();
        $ids = [];
        foreach ($classes->wherePivot('statut', 'actif')->where($classes->getRelated()->qualifyColumn('institution_id'), $user->institution_id)->pluck('classes.id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Les élèves du pivot actif de la classe : l'inverse de la requête ci-dessus.
     * Elle ne borne pas l'établissement des élèves (le lecteur le fait).
     */
    public function localStudentIdsOf(Classe $classe): array
    {
        $ids = [];
        foreach (DB::table('classe_etudiant')->where('classe_id', $classe->getKey())->where('statut', 'actif')->orderBy('user_id')->pluck('user_id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    public function classeIdsForTeacher(User $teacher): array
    {
        if (! is_int($teacher->institution_id)) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = Classe::query()
            ->where('institution_id', $teacher->institution_id)
            ->whereHas(
                'matieres',
                static fn ($query) => $query->where('classe_matiere.enseignant_id', $teacher->id),
            )
            ->pluck('id')
            ->all();

        return $ids;
    }
}
