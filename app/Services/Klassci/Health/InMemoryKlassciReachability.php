<?php

declare(strict_types=1);

namespace App\Services\Klassci\Health;

/**
 * Double en mémoire (#744). Prouve la substituabilité de {@see KlassciReachability}
 * et rend la logique de la sonde testable sans le moindre appel réseau.
 *
 * Une cible jamais déclarée est rendue injoignable : en cas de doute, le test
 * décrit une panne plutôt qu'un succès silencieux.
 */
final class InMemoryKlassciReachability implements KlassciReachability
{
    /** @var array<string, ReachabilityMeasure> */
    private array $mesures = [];

    public function reachable(string $baseUrl, int $connectMs, ?int $status = 200): self
    {
        $this->mesures[$baseUrl] = ReachabilityMeasure::reached($connectMs, $status);

        return $this;
    }

    public function unreachable(string $baseUrl, string $error): self
    {
        $this->mesures[$baseUrl] = ReachabilityMeasure::unreachable($error);

        return $this;
    }

    public function probe(string $baseUrl): ReachabilityMeasure
    {
        return $this->mesures[$baseUrl] ?? ReachabilityMeasure::unreachable('cible non declaree dans le double');
    }
}
