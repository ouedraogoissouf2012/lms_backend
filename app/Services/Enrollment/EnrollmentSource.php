<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\User;

/**
 * Inscriptions locales (#712). Nommée par la préoccupation, pas par le mode.
 */
interface EnrollmentSource
{
    /**
     * @return list<int>
     */
    public function localClasseIdsFor(User $user): array;

    /**
     * @return list<int>
     */
    public function classeIdsForTeacher(User $teacher): array;
}
