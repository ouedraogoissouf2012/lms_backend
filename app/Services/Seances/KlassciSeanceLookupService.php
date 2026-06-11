<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\User;
use App\Services\KlassciProxyService;
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
 * @see \App\Services\SeanceDetailQueryService (orchestrator)
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

        foreach ($dashboard['data']['matieres'] ?? [] as $matiere) {
            $matiereDetails = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "matieres/{$matiere['id']}",
                'GET'
            );

            $seanceTrouvee = collect($matiereDetails['data']['seances_programmees'] ?? [])
                ->firstWhere('id', $seanceId);

            if ($seanceTrouvee) {
                $matiereInfo = $matiereDetails['data']['matiere'] ?? $matiere;
                $seanceTrouvee['enseignant'] = [
                    'id' => $user->klassci_id,
                    'nom' => $user->name,
                    'email' => $user->email
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

            foreach ($dashboard['data']['cours'] ?? [] as $matiere) {
                $matiereId = $matiere['id'] ?? $matiere['matiere_id'] ?? $matiere['matiere']['id'] ?? null;
                if (!$matiereId) {
                    continue;
                }

                $matiereDetails = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "matieres/{$matiereId}",
                    'GET'
                );

                $seanceTrouvee = collect($matiereDetails['data']['seances_programmees'] ?? [])
                    ->firstWhere('id', $seanceId);

                if ($seanceTrouvee) {
                    $matiereInfo = $matiereDetails['data']['matiere'] ?? $matiere;

                    // L'API KLASSCI ne retourne pas l'enseignant dans seances_programmees;
                    // le récupérer depuis matiereDetails ou matiereInfo.
                    $enseignants = $matiereDetails['data']['enseignants'] ?? [];
                    if (empty($enseignants) && isset($matiereInfo['enseignant'])) {
                        $seanceTrouvee['enseignant'] = $matiereInfo['enseignant'];
                    } elseif (!empty($enseignants)) {
                        $seanceTrouvee['enseignant'] = $enseignants[0];
                    }

                    return [$seanceTrouvee, $matiereInfo];
                }
            }

            return [null, null];

        } catch (\Exception $e) {
            $this->logger->error('Erreur récupération séance étudiant via API KLASSCI', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
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

        foreach ($matieresResponse['data'] ?? [] as $matiere) {
            $matiereDetails = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "matieres/{$matiere['id']}",
                'GET'
            );

            $seanceTrouvee = collect($matiereDetails['data']['seances_programmees'] ?? [])
                ->firstWhere('id', $seanceId);

            if ($seanceTrouvee) {
                $matiereInfo = $matiereDetails['data']['matiere'] ?? $matiere;
                return [$seanceTrouvee, $matiereInfo];
            }
        }

        return [null, null];
    }
}
