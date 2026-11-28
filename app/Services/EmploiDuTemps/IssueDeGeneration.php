<?php

declare(strict_types=1);

namespace App\Services\EmploiDuTemps;

/**
 * Ce que la génération retient d'UNE séance (#1669) : créée, ignorée avec son motif (et les conflits), ou passée sous
 * silence (son début est déjà passé). Rendu par la transaction de {@see GenerationDesSeances} : `ConnectionInterface::
 * transaction()` rend `mixed`, l'objet est vérifié par `instanceof` à la sortie.
 */
final readonly class IssueDeGeneration
{
    /** @param  list<int>  $conflits */
    private function __construct(
        public bool $creee,
        public ?string $motif,
        public array $conflits,
    ) {}

    public static function creee(): self
    {
        return new self(true, null, []);
    }

    /** @param  list<int>  $conflits */
    public static function ignoree(string $motif, array $conflits = []): self
    {
        return new self(false, $motif, $conflits);
    }

    public static function passee(): self
    {
        return new self(false, null, []);
    }
}
