<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\User;

/**
 * Le roster ACTIF d'une classe, `lms/classes/{id}/etudiants`.
 *
 * La signature est celle de {@see ClasseEtudiantsQueryService}, mot pour mot.
 * La route reste réservée au personnel (GHSA-gg7j) ; chaque source applique en
 * plus sa propre règle d'accès à la classe.
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
interface ClasseEtudiantsReader
{
    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function getEtudiants(int $classeId, User $user): array;
}
