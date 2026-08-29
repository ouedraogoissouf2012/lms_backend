<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * Résultat d'une agrégation de recherche globale, ET l'état de santé des sources
 * qui l'ont produit (#505).
 *
 * Sans ce couple, un bucket vide est ambigu : « aucun résultat correspondant » et
 * « source indisponible » se ressemblent trait pour trait. C'est précisément cette
 * ambiguïté que l'issue demande de lever — le drapeau voyage donc avec les données
 * plutôt qu'à côté d'elles.
 *
 * @phpstan-type SearchResults array{
 *     users: array<int, array<string, mixed>>,
 *     lessons: array<int, array<string, mixed>>,
 *     evaluations: array<int, array<string, mixed>>,
 *     classes: array<int, array<string, mixed>>,
 *     matieres: array<int, array<string, mixed>>
 * }
 */
final readonly class SearchAggregate
{
    /**
     * @param  SearchResults  $results
     * @param  list<string>  $failedSources  Noms des buckets dont la source est tombée.
     */
    public function __construct(
        public array $results,
        public array $failedSources = [],
    ) {
    }

    /**
     * Toutes les sources ont-elles abouti ? Seul un agrégat complet mérite d'être
     * mis en cache : mémoriser une dégradation la ferait survivre à la panne.
     */
    public function isComplete(): bool
    {
        return $this->failedSources === [];
    }
}
