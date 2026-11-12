<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

/**
 * La structure de l'établissement, ses filières et ses niveaux d'études :
 * `GET /api/proxy/structure` (lot 3c).
 *
 * Nommée par ce qu'elle rend, jamais par le mode : la route reste la même dans
 * les deux types d'école, la source est choisie au point de liaison
 * (ADR-EI-01).
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
interface InstitutionStructureReader
{
    /**
     * L'enveloppe rendue telle quelle par la route.
     *
     * @return array<string, mixed>
     */
    public function structure(): array;
}
