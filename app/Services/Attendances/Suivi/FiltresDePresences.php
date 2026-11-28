<?php

declare(strict_types=1);

namespace App\Services\Attendances\Suivi;

use App\Enums\AttendanceMark;

/**
 * Les filtres du suivi des présences (#1683) : la plage (obligatoire), la classe, la matière, l'élève, la marque
 * retenue, la page.
 */
final readonly class FiltresDePresences
{
    public function __construct(
        public string $du,
        public string $au,
        public ?int $classeId = null,
        public ?int $matiereId = null,
        public ?int $eleveId = null,
        public ?AttendanceMark $marque = null,
        public int $page = 1,
    ) {}
}
