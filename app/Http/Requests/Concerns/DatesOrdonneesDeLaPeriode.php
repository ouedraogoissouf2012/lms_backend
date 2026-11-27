<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * Les cinq dates d'une Période, et leur ordre (ADR-711-04), pour la création (#827) comme pour la
 * modification (#1651).
 *
 * La base porte la contrainte de dernier recours ; sans cette validation, une saisie désordonnée
 * remonterait en 500 au lieu d'un 422 lisible. Chaque date est comparée à la PRÉCÉDENTE
 * RENSEIGNÉE : comparer seulement à la voisine immédiate laisserait passer `opens > starts` dès que
 * `closes` est absente — même raisonnement que le `COALESCE` de la migration.
 *
 * Requiert une `FormRequest` (`filled()`).
 */
trait DatesOrdonneesDeLaPeriode
{
    /** Les cinq dates, dans l'ordre chronologique. */
    protected const DATES_DE_LA_PERIODE = [
        'enrollment_opens_at',
        'enrollment_closes_at',
        'starts_on',
        'ends_on',
        'certificate_available_at',
    ];

    /**
     * @param  list<string>  $presence  `['nullable']` à la création, `['present', 'nullable']` à la
     *                                  modification (qui remplace les cinq dates d'un bloc)
     * @return array<string, list<string>>
     */
    protected function reglesDesDates(array $presence): array
    {
        $regles = [];
        foreach (self::DATES_DE_LA_PERIODE as $i => $date) {
            $regles[$date] = [...$presence, 'date', ...$this->apresLesPrecedentes(array_slice(self::DATES_DE_LA_PERIODE, 0, $i))];
        }

        return $regles;
    }

    /**
     * @param  list<string>  $precedentes
     * @return list<string>
     */
    private function apresLesPrecedentes(array $precedentes): array
    {
        $renseignees = array_values(array_filter($precedentes, fn (string $date): bool => $this->filled($date)));

        return $renseignees === [] ? [] : ['after_or_equal:'.end($renseignees)];
    }
}
