<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\User;

/**
 * Les classes que l'appelant enseigne, pour l'écran « Mes classes ».
 *
 * Nommée par ce qu'elle rend, jamais par le mode : la route reste la même dans
 * les deux types d'école, la source est choisie au point de liaison
 * (ADR-EI-01). La signature est celle de {@see TeacherClassesQueryService}, mot
 * pour mot : l'école créée depuis KLASSCI reste prouvée par ses tests, sans
 * retouche.
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
interface TeacherClassesReader
{
    /**
     * @return list<array{id: int, libelle: string|null, code: string|null, effectif: int|null, effectif_actuel: int|null}>
     */
    public function listFor(User $teacher): array;
}
