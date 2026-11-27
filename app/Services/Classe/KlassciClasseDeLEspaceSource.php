<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\Classe;

/**
 * #1676, école KLASSCI : l'identifiant est l'identité KLASSCI de la classe, lue sur son miroir
 * local dans l'établissement ({@see Classe::localIdForKlassciId()}). Sans miroir, aucune classe.
 */
final class KlassciClasseDeLEspaceSource implements ClasseDeLEspaceSource
{
    public function trouver(int $identifiant, int $etablissement): ?Classe
    {
        $locale = Classe::localIdForKlassciId($identifiant, $etablissement);

        return $locale === null ? null : Classe::query()->withoutGlobalScope('institution')
            ->where('institution_id', $etablissement)
            ->find($locale);
    }
}
