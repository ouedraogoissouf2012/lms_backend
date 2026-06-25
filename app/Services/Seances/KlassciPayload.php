<?php

declare(strict_types=1);

namespace App\Services\Seances;

/**
 * Accès DÉFENSIF et TYPÉ aux payloads KLASSCI (réponses JSON non typées, donc
 * `mixed` partout). Évite les casts `mixed → int` aveugles — proscrits par
 * larastan (« Do not add type casts just to silence errors ») — en narrowant
 * explicitement : un ID non entier/non numérique devient `null`, un sous-arbre
 * non-array devient `[]`. Aucune exception levée.
 */
final class KlassciPayload
{
    /**
     * Identifiant KLASSCI typé, ou null si absent / non numérique.
     */
    public static function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Sous-arbre garanti tableau associatif (sinon tableau vide).
     *
     * @return array<string, mixed>
     */
    public static function asArray(mixed $value): array
    {
        /** @var array<string, mixed> $value */
        return is_array($value) ? $value : [];
    }

    /**
     * Chaîne typée, ou null si la valeur n'est pas une chaîne. Évite de passer
     * du `mixed` aux signatures `?string` (ex. {@see SeanceProgrammationNormalizer::alignDate}).
     */
    public static function toStringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Liste garantie tableau (sinon tableau vide).
     *
     * @return array<int, mixed>
     */
    public static function asList(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Liste d'éléments tous garantis tableaux associatifs — pour alimenter une
     * `Collection<int, array<string, mixed>>` (type attendu par les mappers de
     * séances) sans déclencher d'erreur de covariance larastan.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listOfArrays(mixed $value): array
    {
        return array_map(self::asArray(...), self::asList($value));
    }
}
