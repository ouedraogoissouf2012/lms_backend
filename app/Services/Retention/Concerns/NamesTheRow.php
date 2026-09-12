<?php

declare(strict_types=1);

namespace App\Services\Retention\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Rend l'identifiant d'une ligne affichable, sans supposer son type (#690).
 *
 * `Model::getKey()` est typé `mixed` : une clé primaire peut être un entier, une
 * chaîne, un UUID. Le niveau 9 refuse donc de l'interpoler directement, et il a
 * raison — un objet ou un tableau y produirait une conversion silencieuse.
 *
 * Ce trait existe plutôt qu'un helper recopié dans chaque politique : dupliquer
 * trois lignes dans toutes les politiques serait très exactement le défaut que
 * cette issue corrige.
 */
trait NamesTheRow
{
    /**
     * L'identifiant, ou `?` si la clé n'est pas affichable. On ne lève pas : une
     * description sert à lire une simulation, elle ne doit jamais interrompre
     * une purge.
     */
    private function identifiant(Model $item): string
    {
        $cle = $item->getKey();

        return is_scalar($cle) ? (string) $cle : '?';
    }
}
