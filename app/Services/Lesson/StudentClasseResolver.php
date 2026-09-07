<?php

declare(strict_types=1);

namespace App\Services\Lesson;

use App\Models\User;
use App\Services\Enrollment\EnrollmentSource;

/**
 * #712 — pont historique. Délègue à {@see EnrollmentSource}.
 */
final class StudentClasseResolver
{
    public function __construct(
        private readonly EnrollmentSource $enrollments,
    ) {
    }

    /**
     * @return list<int>
     */
    public function localClasseIdsFor(User $user): array
    {
        return $this->enrollments->localClasseIdsFor($user);
    }
}
