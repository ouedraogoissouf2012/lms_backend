<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\Classe;
use App\Models\User;
use App\Services\Enrollment\EnrollmentSource;

/**
 * Liste des classes d'un enseignant depuis le pivot local (#712).
 */
final class TeacherClassesQueryService
{
    public function __construct(
        private readonly EnrollmentSource $enrollment,
    ) {}

    /**
     * @return list<array{id: int, libelle: string|null, code: string|null, effectif: int|null, effectif_actuel: int}>
     */
    public function listFor(User $teacher): array
    {
        $ids = $this->enrollment->classeIdsForTeacher($teacher);
        if ($ids === []) {
            return [];
        }

        return Classe::query()
            ->whereIn('id', $ids)
            ->withCount(['etudiantsActifs as effectif_actuel'])
            ->orderBy('libelle')
            ->get()
            ->map(static fn (Classe $classe): array => [
                'id' => $classe->id,
                'libelle' => $classe->libelle,
                'code' => $classe->code,
                'effectif' => $classe->effectif,
                'effectif_actuel' => (int) $classe->getAttribute('effectif_actuel'),
            ])
            ->all();
    }
}
