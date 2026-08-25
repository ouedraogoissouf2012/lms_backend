<?php

declare(strict_types=1);

namespace App\Services\Integrity;

use InvalidArgumentException;

/**
 * Socle des politiques de survie : départage déterministe (#541).
 *
 * Toutes les politiques classent les candidates de la même façon :
 *
 *   1. score métier décroissant (défini par la politique concrète) ;
 *   2. `updated_at` le plus RÉCENT — à valeur métier égale, la ligne la plus
 *      travaillée est celle qu'un utilisateur a touchée en dernier ;
 *   3. `id` le plus PETIT — départage stable, donc migration reproductible.
 *
 * Le tri est fait en PHP et non en SQL : un groupe en doublon compte quelques
 * lignes (c'est l'anomalie, pas le volume), et l'ordre reste identique sous
 * SQLite comme sous MySQL — aucune dépendance à la collation ou au traitement
 * des NULL du moteur.
 */
abstract class RankedSurvivorPolicy implements DuplicateSurvivorPolicy
{
    public function survivorId(string $table, array $rows): int
    {
        if ($rows === []) {
            throw new InvalidArgumentException("Aucune candidate à conserver pour `{$table}`.");
        }

        $best = $rows[0];
        $bestKey = null;

        foreach ($rows as $row) {
            $key = [
                -$this->score($table, $row),          // score décroissant
                $this->updatedAtRank($row),           // récence décroissante
                RowIdentifier::of($row),              // id croissant
            ];

            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $best = $row;
            }
        }

        return RowIdentifier::of($best);
    }

    /**
     * Valeur métier de la ligne : plus c'est haut, plus il faut la conserver.
     *
     * @param  array<string, mixed>  $row
     */
    abstract protected function score(string $table, array $row): int;

    /**
     * Récence sous forme comparable et DÉCROISSANTE (donc négative).
     *
     * Une ligne sans `updated_at` exploitable est classée la moins récente
     * plutôt que d'être privilégiée par accident.
     *
     * @param  array<string, mixed>  $row
     */
    private function updatedAtRank(array $row): int
    {
        $updatedAt = $row['updated_at'] ?? null;

        if (! is_string($updatedAt) || $updatedAt === '') {
            return 0;
        }

        $timestamp = strtotime($updatedAt);

        return $timestamp === false ? 0 : -$timestamp;
    }
}
