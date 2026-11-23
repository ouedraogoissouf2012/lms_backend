<?php

declare(strict_types=1);

namespace App\Services\Sync\Classes;

use App\Services\Seances\KlassciPayload;

/**
 * La capacité d'une classe KLASSCI, telle que `classes.effectif` la porte depuis #1115 :
 * 0 = sans limite.
 *
 * Elle ne se lit que dans un champ de CAPACITÉ : `places_totales`, puis `places`.
 *
 * `effectif` n'en fait pas partie (#1357) : c'est un nombre d'INSCRITS, comme
 * `places_occupees` ({@see KlassciPayload}). L'écrire comme capacité rendait une classe
 * pleine dès sa synchronisation. Mesuré sur de vraies réponses KLASSCI (base de dev,
 * 2 classes, forme liste) : `places_totales` et `places_occupees`, ni `effectif` ni `places`.
 * `places` reste lu, c'est un nom de capacité.
 *
 * Une valeur non entière ou négative ne devient pas une capacité.
 */
final class CapaciteKlassci
{
    /** @param  array<string, mixed>  $classe */
    public static function de(array $classe): int
    {
        // F2 (falsification #1357) : plus de contrôle d'entier positif, un simple cast
        $brut = $classe['places_totales'] ?? $classe['places'] ?? 0;

        return is_numeric($brut) ? (int) $brut : 0;
    }
}
