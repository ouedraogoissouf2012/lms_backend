<?php

declare(strict_types=1);

namespace App\Support\Config;

/**
 * Le SEUL endroit qui décide comment un entier saisi en configuration, ou en option de
 * commande, est interprété quand il est mal saisi (#738).
 *
 * `config()` et `option()` rendent `mixed` : PHPStan niveau 9 refuse le cast direct, et
 * chaque auteur avait résolu la question dans son coin. Sept copies répondaient la même
 * chose à « que vaut `RECORDINGS_MAX_CONCURRENT=0` ? », sans que rien ne le garantisse.
 *
 * Injectable et sans état (§1.6 D : pas de helper global en code métier). Les cas limites
 * sont testés ici, une fois (`tests/Unit/Support/Config/LecteurDEntiersTest`).
 */
final class LecteurDEntiers
{
    /**
     * Un entier STRICTEMENT positif, sinon le défaut : absent, nul, négatif ou illisible
     * (`'abc'`, `true`, `''`) donne `$defaut`. Une valeur numérique décimale est tronquée.
     */
    public function positif(mixed $valeur, int $defaut): int
    {
        return is_numeric($valeur) && (int) $valeur > 0 ? (int) $valeur : $defaut;
    }

    /**
     * Un entier d'au moins `$plancher` : une valeur illisible donne le plancher, et une
     * valeur trop petite est relevée au plancher. Pour une option de commande dont le
     * défaut est déjà déclaré dans la signature : il n'y a pas de second défaut à
     * inventer, seulement une borne à tenir.
     *
     * Durcissement déclaré (#738) : `'5abc'` n'est plus lu à moitié (5) ; il est
     * illisible, donc le plancher.
     */
    public function auMoins(mixed $valeur, int $plancher): int
    {
        return is_numeric($valeur) ? max($plancher, (int) $valeur) : $plancher;
    }
}
