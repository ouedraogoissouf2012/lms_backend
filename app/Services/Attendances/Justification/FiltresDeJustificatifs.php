<?php

declare(strict_types=1);

namespace App\Services\Attendances\Justification;

use App\Enums\EtatJustificatif;

/**
 * Les filtres de la liste de la gestion (#1681) : l'état, l'élève, la plage des dates de DÉPÔT, la page. Les
 * compteurs par état ignorent le filtre d'état (ils disent ce que chaque onglet contient).
 */
final readonly class FiltresDeJustificatifs
{
    public function __construct(
        public ?EtatJustificatif $etat = null,
        public ?int $eleveId = null,
        public ?string $du = null,
        public ?string $au = null,
        public int $page = 1,
    ) {}
}
