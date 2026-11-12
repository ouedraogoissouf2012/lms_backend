<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\User;

/**
 * Les matières de l'établissement (lot 3c), pour deux routes :
 *
 * - `GET /api/proxy/matieres` ({@see list()}) : la liste des matières que lit le
 *   front partout, dont la création d'une leçon par l'enseignant ;
 * - `GET /api/admin/matieres` ({@see listForAdmin()}) : l'écran d'administration
 *   des matières.
 *
 * Nommée par ce qu'elle rend, jamais par le mode : les routes restent les mêmes
 * dans les deux types d'école, la source est choisie au point de liaison
 * (ADR-EI-01).
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
interface InstitutionMatieresReader
{
    /**
     * L'enveloppe rendue telle quelle par `/proxy/matieres`.
     *
     * @param  array<string, mixed>  $filtres  `filiere_id`, `niveau_id`
     * @return array<string, mixed>
     */
    public function list(User $user, array $filtres): array;

    /**
     * Le contenu de `data` de `/admin/matieres`. Le contrôleur a déjà vérifié
     * que l'appelant relève de la gestion.
     *
     * @return array{matieres: array<int, array<string, mixed>>, statistiques: array<string, mixed>}
     */
    public function listForAdmin(User $user): array;
}
