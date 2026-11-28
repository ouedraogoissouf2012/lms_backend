<?php

declare(strict_types=1);

namespace App\Services\Bulletin;

use Carbon\CarbonImmutable;

/**
 * #1677 : la période d'un bulletin, un INTERVALLE DE DATES NOMMÉ (« 1er trimestre », du 2 septembre
 * au 20 décembre). C'est celui du bilan de la classe (`?du=&au=`). Une sous-période (S1, S2, #1807)
 * s'y branchera sans refonte : elle se traduit en un tel intervalle.
 */
final class PeriodeDuBulletin
{
    public function __construct(
        public readonly string $libelle,
        public readonly CarbonImmutable $du,
        public readonly CarbonImmutable $au,
    ) {}

    /** @return array{libelle: string, du: string, au: string} */
    public function enTableau(): array
    {
        return ['libelle' => $this->libelle, 'du' => $this->du->toDateString(), 'au' => $this->au->toDateString()];
    }
}
