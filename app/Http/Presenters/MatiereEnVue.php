<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Models\Matiere;

/**
 * La vue d'une matière locale rendue après son écriture (#1653), commune à la création et à la
 * modification : l'écran relit la même forme après l'une et l'autre (`MatiereCreated`).
 */
final class MatiereEnVue
{
    /** @return array{id: int, libelle: string, code: string|null, description: string|null, coefficient: int, credit: int} */
    public static function de(Matiere $matiere): array
    {
        return [
            'id' => $matiere->id,
            'libelle' => (string) $matiere->libelle,
            'code' => $matiere->code === null ? null : (string) $matiere->code,
            'description' => $matiere->description === null ? null : (string) $matiere->description,
            'coefficient' => (int) $matiere->coefficient,
            'credit' => (int) $matiere->credit,
        ];
    }
}
