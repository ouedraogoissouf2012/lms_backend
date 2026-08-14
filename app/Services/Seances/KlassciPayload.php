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

    /**
     * Ids entiers uniques extraits d'une liste de payloads, résolus via
     * `$idResolver` (généralement `KlassciPayload::toInt()` sur un champ id
     * direct ou imbriqué). Entrées non résolvables écartées.
     *
     * Centralise l'idiome "foreach + toInt + filtre null + unique" répété
     * sur plusieurs fetchers de séances/classes (issue #517).
     *
     * @param  iterable<array<string, mixed>>  $items
     * @param  \Closure(array<string, mixed>): (int|null)  $idResolver
     * @return list<int>
     */
    public static function uniqueIntIds(iterable $items, \Closure $idResolver): array
    {
        $ids = [];
        foreach ($items as $item) {
            $id = $idResolver($item);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Id entier de la classe d'une séance (`seance.classe.id`), ou `null` si
     * absent/non-numérique.
     *
     * @param  array<string, mixed>  $seance
     */
    public static function classeIdFor(array $seance): ?int
    {
        return self::toInt(self::asArray($seance['classe'] ?? null)['id'] ?? null);
    }

    /**
     * Effectif (`places_occupees`) de la classe d'une séance, depuis un map
     * de détails classes pré-chargé en batch (issue #135/#517) — `0` si la
     * classe est absente/non résolvable/échouée dans le pool.
     *
     * @param  array<string, mixed>  $seance
     * @param  array<int, array<string, mixed>>  $classesDetails
     */
    public static function classeEffectif(array $seance, array $classesDetails): int
    {
        $classeId = self::classeIdFor($seance);
        if ($classeId === null) {
            return 0;
        }

        $classe = self::asArray(self::asArray($classesDetails[$classeId]['data'] ?? null)['classe'] ?? null);
        $occupees = $classe['places_occupees'] ?? 0;

        return is_int($occupees) ? $occupees : (is_numeric($occupees) ? (int) $occupees : 0);
    }
}
