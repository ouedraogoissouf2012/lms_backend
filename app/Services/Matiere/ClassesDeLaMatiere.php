<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\Matiere;
use App\Models\User;

/**
 * Les classes d'une matière, telles que sa page les propose à un compte
 * (`classes_concernees`), en identifiants LOCAUX (#1103).
 *
 * Une seule règle pour proposer et pour accepter : la page de la matière et la
 * validation d'une leçon (`StoreLessonRequest`, `UpdateLessonRequest`) appellent
 * ce port, et une leçon ne peut être rangée que dans une classe proposée. Liée
 * par école au point de liaison (`CatalogueServiceProvider::SOURCES`,
 * ADR-EI-01).
 */
interface ClassesDeLaMatiere
{
    /**
     * @return list<int>
     */
    public function idsPour(Matiere $matiere, User $compte): array;
}
