<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\User;

/**
 * La fiche d'une classe, par la porte locale `lms/classes/local/{id}`.
 *
 * Distincte de {@see ClasseDetailsReader} parce que, dans une école créée
 * depuis KLASSCI, les deux portes ne parlent pas le même espace (ADR-760-01) :
 * la source KLASSCI de celle-ci, {@see ClasseLocalDetailsService}, traduit puis
 * refuse en 409 une classe sans `klassci_id`. Dans une école créée depuis le
 * LMS, une seule classe sert les deux.
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 * @see docs/adr/2026-09-19-760-01-deux-portes-deux-espaces.md
 */
interface ClasseLocalDoorReader
{
    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function detailsForLocalId(int $classeId, User $user): array;
}
