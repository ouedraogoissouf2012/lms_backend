<?php

declare(strict_types=1);

namespace App\Services\Sync\Classes;

use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciPayload;
use Psr\Log\LoggerInterface;

/**
 * KlassciClassesFetcher
 *
 * Récupère la liste des classes (avec étudiants détaillés quand possible) depuis
 * KLASSCI selon le rôle de l'utilisateur. Extrait verbatim de
 * `ClasseSyncService::fetchClassesFromKlassci()` lors du split SRP (§1.1).
 * Batché pour issue #517 (H5, N+1 HTTP) : les boucles séquentielles
 * `classes/{id}` / `matieres/{id}` sont remplacées par `fetchManyClassesDetails`
 * / `fetchManyMatieresDetails` (pool `Http::pool`, issue #135).
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * Constructeur injecte `KlassciProxyService` et `LoggerInterface` (PSR-3).
 * Pas de Facade `Log::` dans le code métier.
 *
 * ## Comportement préservé
 *
 * 1. Essai prioritaire de `GET /classes` (coordinateurs / superAdmin).
 *    Pour chaque classe retournée, on récupère en UN pool batch les détails
 *    (`GET /classes/{id}`) afin d'obtenir les étudiants. En cas d'échec sur
 *    les détails (id absent du map batch), on conserve les infos basiques de
 *    la classe.
 *
 * 2. Fallback selon `$userRole` :
 *    - `etudiant` -> `GET /me/student-dashboard` -> `data.classes`
 *    - `enseignant` / `teacher` -> `GET /me/teacher-dashboard` puis, en UN
 *      pool batch, `GET /matieres/{id}` pour toutes les matières, afin d'en
 *      déduire les classes (déduplique par id).
 *
 * Le fallback n'est déclenché QUE si `/classes` lève une exception (typiquement
 * 403). C'est le comportement historique attendu par
 * `ClasseSyncService::syncUserClasses()`.
 *
 * @see ClasseSyncService::syncUserClasses() — orchestrateur appelant
 */
final class KlassciClassesFetcher
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Récupérer les classes depuis Klassci selon le rôle.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch(string $klassciToken, string $userRole): array
    {
        // Essayer d'abord /classes (pour coordinateurs et superAdmin)
        // Si ça échoue, essayer les autres endpoints spécifiques au rôle
        try {
            return $this->fetchAllClassesWithDetails($klassciToken);
        } catch (\Exception $e) {
            // Si /classes échoue (403, etc.), essayer les autres endpoints
            $this->logger->info('Endpoint /classes non accessible, essai selon rôle', [
                'role' => $userRole,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->fetchByRole($klassciToken, $userRole);
    }

    /**
     * Récupère les détails bruts d'une classe par son ID Klassci.
     *
     * Retourne le `data` brut de `GET /classes/{id}` (typiquement
     * `{classe: {...}, etudiants: [...]}`) ou `null` si l'API ne renvoie pas
     * de payload. Utilisé par `ClasseSyncService::syncClasseById()`.
     *
     * @return array<string, mixed>|null
     */
    public function fetchClasseDetails(string $klassciToken, int $klassciClasseId): ?array
    {
        $response = $this->klassciService->requestWithUserToken(
            $klassciToken,
            "classes/{$klassciClasseId}",
            'GET'
        );

        return $response['data'] ?? null;
    }

    /**
     * Tente `GET /classes` puis enrichit en UN pool batch (#517) via
     * `GET /classes/{id}` pour récupérer les étudiants. Un échec du batch
     * lui-même (pas un échec par id — déjà toléré par `KlassciBatchFetcher`,
     * mais une panne config/connectivité KLASSCI) est capturé ICI et dégradé
     * vers les infos basiques déjà obtenues de `/classes` : il ne doit PAS
     * remonter jusqu'au `try/catch` de `fetch()`, qui interprète toute
     * exception comme "`/classes` inaccessible" et bascule à tort vers le
     * fallback par rôle — perdant les classes déjà listées avec succès.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllClassesWithDetails(string $klassciToken): array
    {
        $response = $this->klassciService->requestWithUserToken(
            $klassciToken,
            'classes',
            'GET'
        );

        /** @var array<int, array<string, mixed>> $classes */
        $classes = $response['data'] ?? [];

        try {
            $detailsMap = $this->fetchManyClasseDetails($classes, $klassciToken);
        } catch (\Exception $e) {
            $this->logger->warning('Erreur récupération détails classes (batch) — infos basiques conservées', [
                'error' => $e->getMessage(),
            ]);

            return $classes;
        }

        $detailedClasses = [];
        foreach ($classes as $classe) {
            $id = KlassciPayload::toInt($classe['id'] ?? null);
            $details = $id !== null ? ($detailsMap[$id] ?? null) : null;
            $data = $details !== null ? KlassciPayload::asArray($details['data'] ?? null) : [];
            $classeDetails = $data['classe'] ?? null;

            // L'API retourne { "data": { "classe": {...}, "etudiants": [...] }}
            if (is_array($classeDetails)) {
                $classeDetails['etudiants'] = $data['etudiants'] ?? [];
                $detailedClasses[] = $classeDetails;
            } else {
                // Détails absents du batch (id échoué / non résoluble) : garder les infos basiques
                $detailedClasses[] = $classe;
            }
        }

        return $detailedClasses;
    }

    /**
     * @param  array<int, array<string, mixed>>  $classes
     * @return array<int, array<string, mixed>>
     */
    private function fetchManyClasseDetails(array $classes, string $klassciToken): array
    {
        $classeIds = KlassciPayload::uniqueIntIds($classes, fn (array $classe): ?int => KlassciPayload::toInt($classe['id'] ?? null));

        return $classeIds === [] ? [] : $this->klassciService->fetchManyClassesDetails($classeIds, $klassciToken);
    }

    /**
     * Fallback selon le rôle quand `GET /classes` n'est pas accessible.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchByRole(string $klassciToken, string $userRole): array
    {
        if ($userRole === 'etudiant') {
            return $this->fetchStudentClasses($klassciToken);
        }

        if (in_array($userRole, ['enseignant', 'teacher'], true)) {
            return $this->fetchTeacherClasses($klassciToken);
        }

        return [];
    }

    /**
     * Récupère les classes d'un étudiant via `/me/student-dashboard`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchStudentClasses(string $klassciToken): array
    {
        $response = $this->klassciService->requestWithUserToken(
            $klassciToken,
            'me/student-dashboard',
            'GET'
        );

        return $response['data']['classes'] ?? [];
    }

    /**
     * Récupère les classes d'un enseignant via `/me/teacher-dashboard` puis,
     * en UN pool batch (#517), les détails de chaque matière (déduplique les
     * classes par id).
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchTeacherClasses(string $klassciToken): array
    {
        $dashboard = $this->klassciService->requestWithUserToken(
            $klassciToken,
            'me/teacher-dashboard',
            'GET'
        );

        /** @var array<int, array<string, mixed>> $matieres */
        $matieres = $dashboard['data']['matieres'] ?? [];
        $matiereIds = KlassciPayload::uniqueIntIds($matieres, fn (array $matiere): ?int => KlassciPayload::toInt($matiere['id'] ?? null));

        $detailsMap = $matiereIds === []
            ? []
            : $this->klassciService->fetchManyMatieresDetails($matiereIds, $klassciToken);

        $classesMap = [];
        foreach ($detailsMap as $matiereDetails) {
            $classe = KlassciPayload::asArray($matiereDetails['data'] ?? null)['classe'] ?? null;
            $classeId = is_array($classe) ? KlassciPayload::toInt($classe['id'] ?? null) : null;
            if ($classeId !== null && ! isset($classesMap[$classeId])) {
                $classesMap[$classeId] = $classe;
            }
        }

        return array_values($classesMap);
    }
}
