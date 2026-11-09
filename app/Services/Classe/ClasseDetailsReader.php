<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\User;

/**
 * La fiche d'une classe, par la porte historique `lms/classes/{id}`.
 *
 * Même forme que {@see TeacherClassesReader} : une interface par ce qu'elle
 * rend, deux sources, le choix au point de liaison. La signature est celle de
 * {@see ClasseDetailsQueryService}, mot pour mot.
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 * @see docs/adr/2026-09-19-760-01-deux-portes-deux-espaces.md
 */
interface ClasseDetailsReader
{
    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function getDetailsForUser(int $classeId, User $user): array;
}
