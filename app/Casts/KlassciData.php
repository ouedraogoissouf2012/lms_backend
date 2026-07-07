<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast du blob `klassci_data` (JSON string ou colonne JSON native → array).
 *
 * ⚠️ SÉCURITÉ — ce blob est un cache display informationnel, écrasé EN BLOC à
 * chaque re-sync KLASSCI 24h (`EnsureKlassciSync`). Une instance KLASSCI
 * compromise peut y pousser n'importe quoi.
 *
 * NE JAMAIS lire `$user->klassci_data['XXX_id']` pour de l'AUTORISATION.
 * Champs d'autorité write-once dédiés : `role` (LMS), `klassci_role` (info,
 * CRITICAL-05), `klassci_enseignant_id` (ownership évaluations, #119).
 * Lecture pour affichage / rapports : OK.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>|string|null>
 */
final class KlassciData implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (is_array($value)) {
            return self::normalizeStringKeys($value);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? self::normalizeStringKeys($decoded) : [];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (is_array($value)) {
            return json_encode($value) ?: null;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>
     */
    private static function normalizeStringKeys(array $value): array
    {
        $normalized = [];

        foreach ($value as $itemKey => $itemValue) {
            $normalized[(string) $itemKey] = $itemValue;
        }

        return $normalized;
    }
}
