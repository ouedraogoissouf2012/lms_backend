<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\User;

/**
 * Les matières qu'enseigne l'appelant, pour l'écran « Mes matières ».
 *
 * Nommée par ce qu'elle rend, jamais par le mode d'établissement : la route
 * reste la même dans les deux volets, seule la source change. Le choix se fait
 * au point de liaison (`RosterAuthorityFactory`), cité en prose pour la même
 * raison que dans `CatalogueAuthority`.
 *
 * La signature est celle de {@see MyMatieresQueryService}, mot pour mot : des
 * tests l'instancient par son nom, et le volet KLASSCI doit rester prouvé par
 * eux, sans retouche.
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
interface TeacherMatieresReader
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMatieresForUser(User $user): array;
}
