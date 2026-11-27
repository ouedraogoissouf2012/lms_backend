<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\Classe;

/** #1676, école créée depuis le LMS : l'identifiant est `classes.id`, dans l'établissement. */
final class LocalClasseDeLEspaceSource implements ClasseDeLEspaceSource
{
    public function trouver(int $identifiant, int $etablissement): ?Classe
    {
        return Classe::query()->withoutGlobalScope('institution')
            ->where('institution_id', $etablissement)
            ->find($identifiant);
    }
}
