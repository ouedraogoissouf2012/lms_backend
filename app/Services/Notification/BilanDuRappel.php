<?php

declare(strict_types=1);

namespace App\Services\Notification;

/** Ce qu'un passage du rappel d'évaluation a fait, pour la sortie de la commande. */
final class BilanDuRappel
{
    /** @param  list<int>  $evaluationsEnEchec */
    public function __construct(
        public readonly int $evaluations,
        public readonly int $rappels,
        public readonly array $evaluationsEnEchec,
    ) {}
}
