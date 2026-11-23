<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\BusinessException;
use App\Models\Classe;

/**
 * Régler la capacité d'une classe existante (#1358, volet back).
 *
 * `classes.effectif` est la CAPACITÉ que l'inscription par code respecte (#1115,
 * {@see Classe::isComplete()}) ; 0 veut dire « non renseignée, sans limite ». Une école
 * autonome la déclare à la création ({@see LocalClasseCreator}) et la modifie ici, par un
 * geste NOMMÉ (`PUT /classes/{classe}/capacite`), sur le modèle du rattachement à une
 * Période (#1208), et non par un `PATCH /classes` général qui ouvrirait d'autres champs.
 *
 * Il passe par la porte du catalogue au point d'écriture : en école KLASSCI, la capacité
 * vient de KLASSCI (`places_totales`), et le geste est refusé (403
 * `catalogue_managed_by_klassci`), comme tout le catalogue local (#1184).
 *
 * Baisser la capacité sous l'effectif actuel n'expulse personne : rien ici ne touche
 * `classe_etudiant`. Seules les NOUVELLES adhésions sont refusées.
 */
final class CapaciteDeClasse
{
    /** La borne haute, la même à la création et à la modification. */
    public const MAXIMUM = 10000;

    public function __construct(private readonly PorteDuCatalogueLocal $porte) {}

    /**
     * @param  int|null  $capacite  `null` ou 0 : sans limite.
     *
     * @throws BusinessException 403 en école KLASSCI, 404 pour une classe hors de l'établissement.
     */
    public function regler(int $classeId, ?int $capacite): Classe
    {
        $ecole = $this->porte->etablissement();

        $classe = Classe::query()->withoutGlobalScope('institution')
            ->where('id', $classeId)
            ->where('institution_id', $ecole)
            ->first();

        if (! $classe instanceof Classe) {
            throw new BusinessException('Classe introuvable dans cet établissement.', 404);
        }

        return $classe;
    }

    /** La capacité telle que l'API la rend : `null` quand elle n'est pas renseignée. */
    public static function lue(Classe $classe): ?int
    {
        return $classe->effectif > 0 ? $classe->effectif : null;
    }
}
