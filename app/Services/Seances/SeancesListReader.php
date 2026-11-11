<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\User;

/**
 * Les listes de séances de l'utilisateur : les séances à venir, l'emploi du
 * temps de l'enseignant, celui de l'élève (ADR-EI-01, lot 3d).
 *
 * Deux sources, choisies par le mode DÉCLARÉ de l'école au point de liaison :
 * KLASSCI ({@see \App\Services\SeancesListQueryService}) et le LMS
 * ({@see LocalSeancesListReader}). Le contrôleur reçoit la source par sa
 * MÉTHODE.
 */
interface SeancesListReader
{
    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     *
     * @throws MissingKlassciTokenException Source KLASSCI, compte sans jeton.
     */
    public function getUpcomingForUser(User $user, int $days, ?int $teacherId, ?int $classeId): array;

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws MissingKlassciTokenException Source KLASSCI, compte sans jeton.
     */
    public function getMyTeachingForUser(User $user): array;

    /**
     * `['classe_missing' => true]` : aucune classe (404). `['empty_matieres' => true]` :
     * une classe sans matière (source KLASSCI seulement).
     *
     * @return array{classe_missing: true}|array{empty_matieres: true}|array<int, array<string, mixed>>
     *
     * @throws MissingKlassciTokenException Source KLASSCI, compte sans jeton.
     */
    public function getMyClassesForUser(User $user): array;
}
