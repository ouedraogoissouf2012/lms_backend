<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\User;

/**
 * La page d'une matière : la matière, ses classes, ses leçons et leurs
 * compteurs.
 *
 * Même forme que {@see TeacherMatieresReader} : une interface par ce qu'elle
 * rend, deux sources, et le choix au point de liaison. La signature est celle
 * de {@see MatiereDetailsQueryService}, mot pour mot.
 *
 * `null` veut dire « introuvable pour cet appelant ». Le contrôleur en fait un
 * 404, sans dire si la matière existe ailleurs.
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
interface MatiereDetailsReader
{
    /**
     * La forme est un CONTRAT des deux sources : le contrôleur journalise
     * `lessons`, `seances_programmees` et `evaluations_programmees` par leur
     * nombre, et le front lit chaque clé (`docs/openapi.yaml`, `PageMatiere`).
     *
     * @return array{matiere: array<string, mixed>, matiere_id_local: int|null, combinaisons: array<int, array<string, mixed>>, enseignants: array<int, array<string, mixed>>, lessons: array<int, array<string, mixed>>, seances_programmees: array<int, array<string, mixed>>, evaluations_programmees: array<int, array<string, mixed>>, classes_concernees: array<int, array{id: int, nom: string}>, statistiques: array<string, mixed>}|null
     */
    public function getDetailsForUser(int $matiereId, User $user): ?array;
}
