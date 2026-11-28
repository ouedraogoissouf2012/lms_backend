<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Models\Filiere;
use App\Models\NiveauEtude;
use Illuminate\Database\Eloquent\Model;

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
     * La filière ou le niveau d'une classe ou d'une matière (#1648, PR B), dans la forme d'une `combinaison` du volet
     * KLASSCI : `{id, nom, code}`, ou `null` sans rattachement.
     *
     * @return array{id: int, nom: string, code: string|null}|null
     */
    public static function resume(?Model $ligne): ?array
    {
        if (! $ligne instanceof Filiere && ! $ligne instanceof NiveauEtude) {
            return null;
        }

        return ['id' => $ligne->id, 'nom' => $ligne->libelle, 'code' => $ligne->code];
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
