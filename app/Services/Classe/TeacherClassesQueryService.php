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

        $rows = [];
        foreach (
            Classe::query()
                ->whereIn('id', $ids)
                ->withCount(['etudiantsActifs as effectif_actuel'])
                ->orderBy('libelle')
                ->get() as $classe
        ) {
            $actuel = $classe->getAttributes()['effectif_actuel'] ?? 0;
            $rows[] = [
                'id' => $classe->id,
                'libelle' => $classe->libelle,
                'code' => $classe->code,
                'effectif' => $classe->effectif,
                'effectif_actuel' => is_numeric($actuel) ? (int) $actuel : 0,
            ];
        }

        return $rows;
    }
}
