<?php

declare(strict_types=1);

namespace App\Services\Klassci;

use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciPayload;

/**
 * Le seul endroit qui sache où KLASSCI range les classes d'un enseignant.
 *
 * ## Pourquoi ce collaborateur existe
 *
 * `me/teacher-dashboard` rend les classes de l'enseignant en UN appel
 * authentifié — donc sur un chemin où `KlassciConfigResolver` résout l'URL par
 * le jeton du porteur (priorité 1), le seul rang qui fonctionne hors login.
 *
 * Deux services lisaient déjà ce payload en recopiant le même chemin
 * `data.classes` : `TeacherClassesQueryService` et, depuis 2026-09-09,
 * `KlassciMatiereClassesSource`. Une clé de payload recopiée est exactement le
 * défaut de #740 — trois lecteurs d'une clé devenue morte, aucun ne le sachant.
 * On n'en pose pas un troisième : on nomme le contrat une fois.
 *
 * ## Ce que ce collaborateur ne fait PAS
 *
 * Il ne rattrape aucune erreur. Chaque appelant décide de son mode dégradé —
 * l'un se replie sur le miroir local, l'autre rend une liste vide — et
 * journalise avec son propre message. Avaler l'exception ici imposerait le même
 * repli à tout le monde.
 *
 * `KlassciClassesFetcher::fetchTeacherClasses()` n'est volontairement PAS
 * migré : il lit `data.matieres` puis remonte aux classes par les détails de
 * matière. C'est une dérivation différente, pas une recopie de celle-ci.
 */
final class TeacherDashboardClasses
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
    ) {}

    /**
     * Les classes brutes du tableau de bord, telles que KLASSCI les rend.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forToken(string $klassciToken): array
    {
        $payload = $this->klassciService->requestWithUserToken($klassciToken, 'me/teacher-dashboard', 'GET');

        return KlassciPayload::listOfArrays(
            KlassciPayload::asArray($payload['data'] ?? null)['classes'] ?? null,
        );
    }
}
