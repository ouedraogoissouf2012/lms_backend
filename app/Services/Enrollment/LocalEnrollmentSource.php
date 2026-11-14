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
    public function localClasseIdsFor(User $user): array
    {
        $ids = [];
        foreach ($user->classes()->wherePivot('statut', 'actif')->pluck('classes.id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Les élèves du pivot actif de la classe : l'inverse de la requête ci-dessus,
     * qui ne borne pas non plus l'établissement (le lecteur le fait).
     */
    public function localStudentIdsOf(Classe $classe): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('classe_etudiant')
            ->where('classe_id', $classe->getKey())
            ->where('statut', 'actif')
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => intval($id))
            ->all();

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
