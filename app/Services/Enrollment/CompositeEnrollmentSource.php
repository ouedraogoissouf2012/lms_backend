<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\User;

/**
 * Pivot local d'abord ; repli cache KLASSCI si le backfill n'a pas tourné.
 * Aucune conditionnelle de mode.
 */
final class CompositeEnrollmentSource implements EnrollmentSource
{
    public function __construct(
        private readonly LocalEnrollmentSource $local,
        private readonly KlassciEnrollmentSource $klassci,
    ) {
    }

    public function localClasseIdsFor(User $user): array
    {
        $fromPivot = $this->local->localClasseIdsFor($user);
        if ($fromPivot !== []) {
            return $fromPivot;
        }

        return $this->klassci->localClasseIdsFor($user);
    }

    public function classeIdsForTeacher(User $teacher): array
    {
        $fromLocal = $this->local->classeIdsForTeacher($teacher);
        if ($fromLocal !== []) {
            return $fromLocal;
        }

        return $this->klassci->classeIdsForTeacher($teacher);
    }
}
