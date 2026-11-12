<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\User;

/**
 * Les classes de l'établissement, pour les écrans de gestion :
 * `GET /api/proxy/classes` (lot 3a).
 *
 * Nommée par ce qu'elle rend, jamais par le mode : la route reste la même dans
 * les deux types d'école, la source est choisie au point de liaison
 * (ADR-EI-01).
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
interface InstitutionClassesReader
{
    /**
     * L'enveloppe rendue telle quelle par la route.
     *
     * @param  array<string, mixed>  $filtres  `filiere_id`, `niveau_id`, `annee_id`
     * @return array<string, mixed>
     */
    public function list(User $user, array $filtres): array;
}
