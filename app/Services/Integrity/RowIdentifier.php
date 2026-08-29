<?php

declare(strict_types=1);

namespace App\Services\Integrity;

/**
 * Lecture typée de la clé primaire d'une ligne brute du Query Builder (#541).
 *
 * Les réparateurs d'intégrité travaillent sur des lignes `stdClass` converties en
 * tableaux : PHPStan les voit donc comme `array<string, mixed>`. Plutôt que de
 * répéter un cast non vérifié à chaque site d'appel, l'invariant (« `id` est la
 * clé primaire auto-incrémentée, donc toujours numérique ») est énoncé ICI, une
 * seule fois.
 *
 * Fonction pure, sans état ni I/O — au même titre que `Str::limit()`, elle n'a
 * rien à injecter et n'a pas à être substituable (§1.6 — D ne s'applique qu'aux
 * collaborations).
 */
final class RowIdentifier
{
    /**
     * @param  array<string, mixed>  $row
     */
    public static function of(array $row): int
    {
        /** @var int|string $id */
        $id = $row['id'];

        return (int) $id;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    public static function allOf(array $rows): array
    {
        return array_map(self::of(...), $rows);
    }
}
