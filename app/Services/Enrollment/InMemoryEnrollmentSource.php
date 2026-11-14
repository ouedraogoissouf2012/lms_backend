<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\Classe;
use App\Models\User;

/**
 * Fake mémoire (#712 art. 5).
 */
final class InMemoryEnrollmentSource implements EnrollmentSource
{
    /** @param array<int, list<int>> $classeIdsByUserId */
    public function __construct(private array $classeIdsByUserId = []) {}

    public function localClasseIdsFor(User $user): array
    {
        return $this->classeIdsByUserId[$user->id] ?? [];
    }

    public function classeIdsForTeacher(User $teacher): array
    {
        return $this->classeIdsByUserId[$teacher->id] ?? [];
    }

    public function localStudentIdsOf(Classe $classe): array
    {
        $ids = [];
        foreach ($this->classeIdsByUserId as $userId => $classeIds) {
            if (in_array((int) $classe->getKey(), $classeIds, true)) {
                $ids[] = (int) $userId;
            }
        }

        return $ids;
    }

    public function assign(int $userId, int $classeId): void
    {
        $this->classeIdsByUserId[$userId][] = $classeId;
    }
}
