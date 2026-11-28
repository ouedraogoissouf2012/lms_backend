<?php

declare(strict_types=1);

namespace App\Services\Attendances\Justification;

/**
 * La pièce d'un justificatif (#1681), telle que la diffuse le contrôleur : son chemin sur le disque privé, le nom
 * proposé au téléchargement, son type.
 */
final readonly class PieceJustificative
{
    public function __construct(
        public string $chemin,
        public string $nom,
        public string $mime,
    ) {}
}
