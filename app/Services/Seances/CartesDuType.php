<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Enums\TypeDeSeance;

/**
 * Le filtre `type` d'une liste de cartes de séance (#1668), pour une source qui ne lit
 * pas une table : les cartes KLASSCI portent déjà `type_seance` (`type_cours` repris, ou
 * `null`). Une carte sans type ne passe jamais un filtre de type.
 */
final class CartesDuType
{
    /**
     * @param  array<int, array<string, mixed>>  $cartes
     * @return array<int, array<string, mixed>>
     */
    public static function garder(array $cartes, ?TypeDeSeance $type): array
    {
        if ($type === null) {
            return $cartes;
        }

        return array_values(array_filter(
            $cartes,
            static fn (array $carte): bool => ($carte['type_seance'] ?? null) === $type->value,
        ));
    }
}
