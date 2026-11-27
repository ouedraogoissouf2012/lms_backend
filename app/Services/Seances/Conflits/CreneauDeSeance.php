<?php

declare(strict_types=1);

namespace App\Services\Seances\Conflits;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Le créneau qu'une séance va occuper (#1668) : `[début, fin effective)`, son enseignant
 * et sa classe, dans un établissement. Construit par {@see ConflitsDHoraire::creneau()},
 * seul à savoir lire la fin effective et les identités de la classe.
 */
final class CreneauDeSeance
{
    public function __construct(
        public readonly int $institutionId,
        public readonly CarbonImmutable $debut,
        public readonly CarbonImmutable $fin,
        public readonly ?User $enseignant,
        public readonly ?int $classeId,
        public readonly ?int $classeKlassciId,
        public readonly ?int $seanceExclue,
    ) {}
}
