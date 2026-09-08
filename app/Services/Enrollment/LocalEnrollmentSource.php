<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\Classe;
use App\Models\User;

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
