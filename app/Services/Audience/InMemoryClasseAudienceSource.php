<?php

declare(strict_types=1);

namespace App\Services\Audience;

use App\Models\Seance;
use App\Models\User;

/**
 * Fake en mémoire (#712 art. 5). Les tests prouvent la substituabilité
 * sans réseau.
 */
final class InMemoryClasseAudienceSource implements ClasseAudienceSource
{
    /** @param array<int, list<int>> $studentIdsBySeanceId */
    public function __construct(private array $studentIdsBySeanceId = []) {}

    public function containsStudent(Seance $seance, User $student): bool
    {
        $ids = $this->studentIdsBySeanceId[$seance->id] ?? [];

        return in_array($student->id, $ids, true);
    }

    public function enroll(int $seanceId, int $studentId): void
    {
        $this->studentIdsBySeanceId[$seanceId][] = $studentId;
    }
}
