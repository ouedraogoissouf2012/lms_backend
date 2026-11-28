<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Services\Bulletin\PeriodeDuBulletin;
use Carbon\CarbonImmutable;

/**
 * #1677 : la période d'un bulletin dans une requête — `libelle`, `du`, `au` (Y-m-d, bornes
 * incluses), en paramètres de requête pour une lecture, dans `periode` pour une écriture.
 */
trait LitLaPeriodeDuBulletin
{
    /** @return array<string, string> */
    private function reglesDeLaPeriode(string $prefixe): array
    {
        return [
            $prefixe.'libelle' => 'required|string|min:1|max:100',
            $prefixe.'du' => 'required|date_format:Y-m-d',
            $prefixe.'au' => 'required|date_format:Y-m-d|after_or_equal:'.$prefixe.'du',
        ];
    }

    private function laPeriode(string $prefixe): PeriodeDuBulletin
    {
        $lire = fn (string $cle): string => (string) $this->validated($prefixe.$cle);

        return new PeriodeDuBulletin(
            trim($lire('libelle')),
            CarbonImmutable::createFromFormat('Y-m-d', $lire('du'))?->startOfDay() ?? CarbonImmutable::now(),
            CarbonImmutable::createFromFormat('Y-m-d', $lire('au'))?->startOfDay() ?? CarbonImmutable::now(),
        );
    }
}
