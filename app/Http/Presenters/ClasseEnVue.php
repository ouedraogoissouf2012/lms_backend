<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Models\Classe;
use App\Services\Catalogue\CapaciteDeClasse;

/**
 * La vue d'une classe locale rendue après son écriture, commune à la création (#848) et à la
 * modification (#1652) : l'écran relit la même forme après l'une et l'autre (`ClasseCreated`).
 */
final class ClasseEnVue
{
    /** @return array{id: int, libelle: string, code: string|null, description: string|null, effectif: int|null} */
    public static function de(Classe $classe): array
    {
        return [
            'id' => $classe->id,
            'libelle' => (string) $classe->libelle,
            'code' => $classe->code === null ? null : (string) $classe->code,
            'description' => $classe->description === null ? null : (string) $classe->description,
            'effectif' => CapaciteDeClasse::lue($classe),
        ];
    }
}
