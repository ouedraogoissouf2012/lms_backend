<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\User;

/**
 * Les participants autorisés d'une séance (`GET /lms/seances/{id}/participants`), par
 * type d'école (ADR-EI-01, « même route, autre source »). La route est réservée au
 * personnel (GHSA-gg7j).
 *
 * - École KLASSCI : `SeanceDetailQueryService`, inchangé (jeton exigé ; la séance est
 *   cherchée dans les matières KLASSCI du porteur).
 * - École en mode autonome ({@see LocalSeanceParticipants}) : la séance locale, sans
 *   jeton ni appel KLASSCI.
 *
 * Liée par `CatalogueServiceProvider` (`bind`), reçue par la MÉTHODE du contrôleur.
 */
interface SeanceParticipantsReader
{
    /**
     * `null` : la séance est introuvable pour cet appelant (404).
     *
     * @return array{seance: array<string, mixed>, teacher: array<string, mixed>|null, students: array<int, array<string, mixed>>, total_participants: int}|null
     */
    public function getSeanceParticipantsArray(int $seanceId, User $user): ?array;
}
