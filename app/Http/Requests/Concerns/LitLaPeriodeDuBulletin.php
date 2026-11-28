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
        return new PeriodeDuBulletin(trim($this->texteValide($prefixe.'libelle')), $this->jourValide($prefixe.'du'), $this->jourValide($prefixe.'au'));
    }

    private function texteValide(string $cle): string
    {
        $valeur = $this->validated($cle);
        if (! is_string($valeur)) {
            throw new \LogicException("La période validée n'a pas de « {$cle} » textuel.");
        }

        return $valeur;
    }

    /** Une date validée `Y-m-d` ; jamais un repli sur aujourd'hui. */
    private function jourValide(string $cle): CarbonImmutable
    {
        $jour = CarbonImmutable::createFromFormat('!Y-m-d', $this->texteValide($cle));
        if (! $jour instanceof CarbonImmutable) {
            throw new \LogicException("La période validée a un « {$cle} » illisible.");
        }

        return $jour;
    }
}
