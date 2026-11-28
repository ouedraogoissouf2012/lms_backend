<?php

declare(strict_types=1);

namespace App\Services\Attendances\Suivi;

/**
 * Lire une ligne brute du constructeur de requêtes (#1683) : un entier ou un texte, par VÉRIFICATION de type, jamais par
 * un cast aveugle. Les moteurs rendent un entier ou une chaîne numérique selon le pilote (SQLite, MySQL).
 */
final class LigneLue
{
    /**
     * @param  array<string, mixed>  $ligne
     */
    public static function entier(array $ligne, string $cle): int
    {
        $valeur = $ligne[$cle] ?? null;

        return is_numeric($valeur) ? (int) $valeur : 0;
    }

    /**
     * @param  array<string, mixed>  $ligne
     */
    public static function entierOuNull(array $ligne, string $cle): ?int
    {
        $valeur = $ligne[$cle] ?? null;

        return is_numeric($valeur) ? (int) $valeur : null;
    }

    /**
     * @param  array<string, mixed>  $ligne
     */
    public static function texte(array $ligne, string $cle): ?string
    {
        $valeur = $ligne[$cle] ?? null;

        return is_scalar($valeur) ? (string) $valeur : null;
    }
}
