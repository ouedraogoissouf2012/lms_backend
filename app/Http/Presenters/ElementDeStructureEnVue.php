<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Models\Filiere;
use App\Models\NiveauEtude;

/**
 * Une filière ou un niveau d'études LOCAL, dans la forme du volet KLASSCI (#1648) : `{id, nom, code}`, et
 * `ordre` pour un niveau. Les écrans qui lisent `GET /proxy/structure` (filtres de l'écran Matières) lisent
 * `nom` : les deux sources leur rendent la même forme.
 */
final class ElementDeStructureEnVue
{
    /** @return array{id: int, nom: string, code: string|null, ordre?: int|null} */
    public static function de(Filiere|NiveauEtude $ligne): array
    {
        $vue = ['id' => $ligne->id, 'nom' => $ligne->libelle, 'code' => $ligne->code];

        if ($ligne instanceof NiveauEtude) {
            $vue['ordre'] = $ligne->ordre;
        }

        return $vue;
    }

    /**
     * @param  list<Filiere|NiveauEtude>  $lignes
     * @return list<array{id: int, nom: string, code: string|null, ordre?: int|null}>
     */
    public static function liste(array $lignes): array
    {
        return array_map(self::de(...), $lignes);
    }
}
