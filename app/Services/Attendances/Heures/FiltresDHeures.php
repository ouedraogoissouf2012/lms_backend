<?php

declare(strict_types=1);

namespace App\Services\Attendances\Heures;

/** Les filtres du rapport des heures (#1682) : la plage de dates (obligatoire), l'enseignant, la classe, la matière. */
final readonly class FiltresDHeures
{
    public function __construct(
        public string $du,
        public string $au,
        public ?int $enseignantId = null,
        public ?int $classeId = null,
        public ?int $matiereId = null,
    ) {}
}
