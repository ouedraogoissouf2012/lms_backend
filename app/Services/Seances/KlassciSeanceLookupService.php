<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\SeanceDetailQueryService;
use Psr\Log\LoggerInterface;

/**
 * KlassciSeanceLookupService — resolves a single séance from the KLASSCI API
 * by iterating through the role-appropriate matières endpoint.
 *
 * Extracted from `SeanceQueryService` (491 lines, §1.1 violation) during split-1.
 *
 * ## Responsibility (SRP)
 *
 * ONE job: given a séance id + user role, return the séance + matière payload
 * from KLASSCI, or `[null, null]` if not found.
 *
 * The three branches (teacher / student / coordinator) share the same shape
 * but use distinct endpoints:
 *   - Teacher  → `me/teacher-dashboard` then `matieres/{id}` per matière.
 *   - Student  → `me/dashboard` (cours) then `matieres/{id}` per matière.
 *   - Other    → `matieres` (full list) then `matieres/{id}` per matière.
 *
 * @see SeanceDetailQueryService (orchestrator)
 */
final class KlassciSeanceLookupService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly KlassciProxyService $klassciService,
    ) {}

    /**
     * Dispatches to the role-specific lookup.
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null} `[seance, matiereInfo]`
     */
    public function lookup(int $seanceId, User $user, string $klassciToken): array
    {
        if ($user->isTeacher()) {
            return $this->lookupForTeacher($seanceId, $user, $klassciToken);
        }

        if ($user->isStudent()) {
            return $this->lookupForStudent($seanceId, $klassciToken);
        }

        // Coordinateur ou supradmin: parcourir toutes les matières
        return $this->lookupForCoordinator($seanceId, $klassciToken);
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function lookupForTeacher(int $seanceId, User $user, string $klassciToken): array
    {
        $dashboard = $this->klassciService->requestWithUserToken(
            $klassciToken,
            'me/teacher-dashboard',
            'GET'
        );

        foreach ($this->dataItems($dashboard, 'matieres') as $matiere) {
            $matiereId = $this->stringId($matiere['id'] ?? null);
            if ($matiereId === null) {
                continue;
            }

            $matiereDetails = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "matieres/{$matiereId}",
                'GET'
            );

            $seanceTrouvee = $this->findSeance($matiereDetails, $seanceId);

            if ($seanceTrouvee) {
                $matiereInfo = $this->matiereInfo($matiereDetails, $matiere);
                $seanceTrouvee['enseignant'] = [
                    'id' => $user->klassci_id,
                    'nom' => $user->name,
                    'email' => $user->email,
                ];

                return [$seanceTrouvee, $matiereInfo];
            }
        }

        return [null, null];
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function lookupForStudent(int $seanceId, string $klassciToken): array
    {
        try {
            $dashboard = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'me/dashboard',
                'GET'
            );

            foreach ($this->dataItems($dashboard, 'cours') as $matiere) {
                $matiereId = $this->studentMatiereId($matiere);
                if ($matiereId === null) {
                    continue;
                }

                $matiereDetails = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "matieres/{$matiereId}",
                    'GET'
                );

                $seanceTrouvee = $this->findSeance($matiereDetails, $seanceId);

                if ($seanceTrouvee) {
                    $matiereInfo = $this->matiereInfo($matiereDetails, $matiere);

                    // L'API KLASSCI ne retourne pas l'enseignant dans seances_programmees;
                    // le récupérer depuis matiereDetails ou matiereInfo.
                    $enseignants = $this->dataItems($matiereDetails, 'enseignants');
                    if ($enseignants === [] && array_key_exists('enseignant', $matiereInfo)) {
                        $seanceTrouvee['enseignant'] = $matiereInfo['enseignant'];
                    } elseif ($enseignants !== []) {
                        $seanceTrouvee['enseignant'] = $enseignants[0];
                    }

                    return [$seanceTrouvee, $matiereInfo];
                }
            }

            return [null, null];

        } catch (\Exception $e) {
            $this->logger->error('Erreur récupération séance étudiant via API KLASSCI', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
            ]);

            return [null, null];
        }
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function lookupForCoordinator(int $seanceId, string $klassciToken): array
    {
        $matieresResponse = $this->klassciService->requestWithUserToken(
            $klassciToken,
            'matieres',
            'GET'
        );

        foreach ($this->arrayItems($matieresResponse['data'] ?? null) as $matiere) {
            $matiereId = $this->stringId($matiere['id'] ?? null);
            if ($matiereId === null) {
                continue;
            }

            $matiereDetails = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "matieres/{$matiereId}",
                'GET'
            );

            $seanceTrouvee = $this->findSeance($matiereDetails, $seanceId);

            if ($seanceTrouvee) {
                $matiereInfo = $this->matiereInfo($matiereDetails, $matiere);

                return [$seanceTrouvee, $matiereInfo];
            }
        }

        return [null, null];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    private function dataItems(array $response, string $key): array
    {
        $data = $response['data'] ?? null;
        if (! is_array($data)) {
            return [];
        }

        return $this->arrayItems($data[$key] ?? null);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function arrayItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $matiereDetails
     * @return array<string, mixed>|null
     */
    private function findSeance(array $matiereDetails, int $seanceId): ?array
    {
        foreach ($this->dataItems($matiereDetails, 'seances_programmees') as $seance) {
            if ($this->matchesId($seance['id'] ?? null, $seanceId)) {
                return $seance;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $matiereDetails
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function matiereInfo(array $matiereDetails, array $fallback): array
    {
        $data = $matiereDetails['data'] ?? null;
        if (! is_array($data)) {
            return $fallback;
        }

        $matiere = $data['matiere'] ?? null;

        return is_array($matiere) ? $matiere : $fallback;
    }

    /**
     * @param  array<string, mixed>  $matiere
     */
    private function studentMatiereId(array $matiere): ?string
    {
        $nestedMatiere = $matiere['matiere'] ?? null;
        $nestedId = is_array($nestedMatiere) ? ($nestedMatiere['id'] ?? null) : null;

        return $this->stringId($matiere['id'] ?? $matiere['matiere_id'] ?? $nestedId);
    }

    private function matchesId(mixed $candidate, int $expected): bool
    {
        if (is_int($candidate)) {
            return $candidate === $expected;
        }

        return is_string($candidate) && ctype_digit($candidate) && (int) $candidate === $expected;
    }

    private function stringId(mixed $id): ?string
    {
        if (is_int($id) || is_string($id)) {
            $value = trim((string) $id);

            return $value === '' ? null : $value;
        }

        return null;
    }
}
