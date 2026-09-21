<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use Illuminate\Support\Str;
use Random\RandomException;

/**
 * Le code court qu'on dicte pour rejoindre une classe (#846, ADR-803-03).
 *
 * ## L'alphabet est une décision d'usage, pas d'informatique
 *
 * `0` et `O`, `1` et `I` et `L` se confondent à l'oral comme à l'écrit sur un
 * bout de papier. L'ADR les exclut nommément, parce que ce code circule par
 * téléphone et par WhatsApp — pas par copier-coller. Restent 31 caractères.
 *
 * ## Six caractères, et pourquoi ce nombre
 *
 * L'ADR dit « court » sans chiffrer. Six se dicte d'une traite et laisse
 * 31⁶ ≈ 887 millions de combinaisons par établissement.
 *
 * Quatre (≈ 923 000) serait devinable par force brute sur un endpoint public,
 * même limité en débit. Huit cesserait d'être dictable sans le faire répéter.
 * Le nombre est donc borné des deux côtés, pas choisi au hasard.
 *
 * ## La casse est normalisée ICI, jamais par le moteur
 *
 * Mesuré le 2026-09-21 : `WHERE c = 'abc'` sur `'ABC'` rend **0 ligne sous
 * SQLite**, et la ligne sous MySQL. S'en remettre à la collation donnerait deux
 * comportements — et des tests verts sur un défaut réel de production.
 *
 * `Str::random()` n'est pas utilisée : elle tire dans un alphabet imposé.
 * `random_int()` est une source cryptographique, et l'alphabet est le nôtre.
 */
final class ClasseEnrolmentCode
{
    /** 31 caractères : ni `0`, ni `1`, ni `I`, ni `L`, ni `O`. */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const LONGUEUR = 6;

    /**
     * Un code tiré au sort, déjà normalisé.
     *
     * @throws RandomException si la source d'aléa est indisponible
     */
    public static function tirer(): string
    {
        $dernier = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < self::LONGUEUR; $i++) {
            $code .= self::ALPHABET[random_int(0, $dernier)];
        }

        return $code;
    }

    /**
     * La forme sous laquelle un code est STOCKÉ et COMPARÉ.
     *
     * Appelée des deux côtés — à l'écriture et à la lecture — sans quoi
     * l'insensibilité à la casse dépendrait du moteur. Les espaces encadrants
     * sont retirés : un code recopié depuis un message en traîne souvent.
     */
    public static function normaliser(string $brut): string
    {
        return Str::upper(trim($brut));
    }
}
