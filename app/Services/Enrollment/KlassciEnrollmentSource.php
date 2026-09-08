<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\Classe;
use App\Models\User;
use App\Models\UserClass;

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

    public function classeIdsForTeacher(User $teacher): array
    {
        /** @var list<int> $ids */
        $ids = Classe::query()
            ->when(
                $teacher->institution_id !== null,
                fn ($query) => $query->where('institution_id', $teacher->institution_id)
            )
            ->whereHas(
                'matieres',
                static fn ($query) => $query->where('classe_matiere.enseignant_id', $teacher->id),
            )
            ->pluck('id')
            ->all();

        return $ids;
    }
}
