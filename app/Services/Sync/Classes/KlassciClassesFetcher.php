<?php

declare(strict_types=1);

namespace App\Services\Sync\Classes;

use App\Services\KlassciProxyService;
use Psr\Log\LoggerInterface;

/**
 * KlassciClassesFetcher
 *
 * Récupère la liste des classes (avec étudiants détaillés quand possible) depuis
 * KLASSCI selon le rôle de l'utilisateur. Extrait verbatim de
 * `ClasseSyncService::fetchClassesFromKlassci()` lors du split SRP (§1.1).
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * Constructeur injecte `KlassciProxyService` et `LoggerInterface` (PSR-3).
 * Pas de Facade `Log::` dans le code métier.
 *
 * ## Comportement préservé
 *
 * 1. Essai prioritaire de `GET /classes` (coordinateurs / superAdmin).
 *    Pour chaque classe retournée, on tente `GET /classes/{id}` afin de
 *    récupérer les étudiants. En cas d'échec sur les détails, on conserve
 *    les infos basiques de la classe.
 *
 * 2. Fallback selon `$userRole` :
 *    - `etudiant` -> `GET /me/student-dashboard` -> `data.classes`
 *    - `enseignant` / `teacher` -> `GET /me/teacher-dashboard` puis pour chaque
 *      matière, `GET /matieres/{id}` afin d'en déduire la classe (déduplique
 *      par id).
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
        // Si ça échoue, essayer les endpoints spécifiques au rôle
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
     * Tente `GET /classes` puis enrichit chaque classe via `GET /classes/{id}`
     * pour récupérer les étudiants.
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

        $classes = $response['data'] ?? [];

        // Si on a des classes, récupérer les détails de chaque classe pour avoir les étudiants
        $detailedClasses = [];
        foreach ($classes as $classe) {
            try {
                $details = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "classes/{$classe['id']}",
                    'GET'
                );

                // L'API retourne { "data": { "classe": {...}, "etudiants": [...] }}
                if (isset($details['data']['classe'])) {
                    $classeDetails = $details['data']['classe'];
                    // Ajouter les étudiants à l'objet classe
                    $classeDetails['etudiants'] = $details['data']['etudiants'] ?? [];
                    $detailedClasses[] = $classeDetails;
                } else {
                    $detailedClasses[] = $classe;
                }
            } catch (\Exception $e) {
                // Si détails non disponibles, garder les infos basiques
                $detailedClasses[] = $classe;
            }
        }

        return $detailedClasses;
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
     * Récupère les classes d'un enseignant via `/me/teacher-dashboard` puis
     * les détails de chaque matière (déduplique les classes par id).
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

        $matieres = $dashboard['data']['matieres'] ?? [];
        $classesMap = [];

        foreach ($matieres as $matiere) {
            // Récupérer les détails de chaque matière pour avoir les classes
            $matiereDetails = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "matieres/{$matiere['id']}",
                'GET'
            );

            $classe = $matiereDetails['data']['classe'] ?? null;
            if ($classe && !isset($classesMap[$classe['id']])) {
                $classesMap[$classe['id']] = $classe;
            }
        }

        return array_values($classesMap);
    }
}
