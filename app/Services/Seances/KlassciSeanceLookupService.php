<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\User;
use App\Services\KlassciProxyService;
use Psr\Log\LoggerInterface;

/**
 * KlassciSeanceLookupService — resolves a single séance from the KLASSCI API
 * by locating the matière that contains it.
 *
 * Extracted from `SeanceQueryService` (491 lines, §1.1 violation) during split-1.
 * Refactored for issue #517 (N+1 HTTP) : the actual matching algorithm (fast
 * local path + batched fallback) now lives in {@see KlassciSeanceMatiereScanner},
 * replacing 3 duplicated sequential-HTTP loops. This class stays a thin
 * orchestrator: build the role-specific candidate set, delegate, shape the
 * response.
 *
 * ## Responsibility (SRP)
 *
 * ONE job: given a séance id + user role, return the séance + matière payload
 * from KLASSCI, or `[null, null]` if not found. The three branches (teacher /
 * student / coordinator) differ only in which endpoint supplies the candidate
 * matière set, and how the `enseignant` field is attached afterward.
 *
 * @see SeanceDetailQueryService (orchestrator)
 */
final class KlassciSeanceLookupService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly KlassciProxyService $klassciService,
        private readonly KlassciSeanceMatiereScanner $scanner,
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

        $matieres = KlassciPayload::listOfArrays(
            KlassciPayload::asArray($dashboard['data'] ?? null)['matieres'] ?? null
        );
        $matieresById = $this->keyByMatiereId($matieres, fn (array $m): ?int => KlassciPayload::toInt($m['id'] ?? null));

        [$seanceTrouvee, $matiereDetails, $matiereFallback] = $this->scanner->scan($matieresById, $seanceId, $klassciToken);

        if ($seanceTrouvee === null) {
            return [null, null];
        }

        $matiereInfo = $this->matiereInfo($matiereDetails, $matiereFallback);
        $seanceTrouvee['enseignant'] = [
            'id' => $user->klassci_id,
            'nom' => $user->name,
            'email' => $user->email,
        ];

        return [$seanceTrouvee, $matiereInfo];
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

            $cours = KlassciPayload::listOfArrays(
                KlassciPayload::asArray($dashboard['data'] ?? null)['cours'] ?? null
            );
            $matieresById = $this->keyByMatiereId($cours, fn (array $m): ?int => $this->studentMatiereId($m));

            [$seanceTrouvee, $matiereDetails, $matiereFallback] = $this->scanner->scan($matieresById, $seanceId, $klassciToken);

            if ($seanceTrouvee === null) {
                return [null, null];
            }

            $matiereInfo = $this->matiereInfo($matiereDetails, $matiereFallback);

            // L'API KLASSCI ne retourne pas l'enseignant dans seances_programmees;
            // le récupérer depuis matiereDetails ou matiereInfo.
            // NB : filtre `is_array` (pas `KlassciPayload::listOfArrays`, qui
            // COERCE une entrée non-array en `[]` au lieu de l'écarter) — ici
            // le test `$enseignants === []` pilote directement la branche, une
            // entrée malformée coercée compterait à tort comme "présente" et
            // écraserait le fallback `matiereInfo['enseignant']` avec `[]`.
            $enseignants = array_values(array_filter(
                KlassciPayload::asList(KlassciPayload::asArray($matiereDetails['data'] ?? null)['enseignants'] ?? null),
                static fn (mixed $item): bool => is_array($item),
            ));
            if ($enseignants === [] && array_key_exists('enseignant', $matiereInfo)) {
                $seanceTrouvee['enseignant'] = $matiereInfo['enseignant'];
            } elseif ($enseignants !== []) {
                $seanceTrouvee['enseignant'] = $enseignants[0];
            }

            return [$seanceTrouvee, $matiereInfo];
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

        $matieres = KlassciPayload::listOfArrays($matieresResponse['data'] ?? null);
        $matieresById = $this->keyByMatiereId($matieres, fn (array $m): ?int => KlassciPayload::toInt($m['id'] ?? null));

        [$seanceTrouvee, $matiereDetails, $matiereFallback] = $this->scanner->scan($matieresById, $seanceId, $klassciToken);

        if ($seanceTrouvee === null) {
            return [null, null];
        }

        return [$seanceTrouvee, $this->matiereInfo($matiereDetails, $matiereFallback)];
    }

    /**
     * Indexe une liste de matières par id typé, en écartant les entrées sans
     * id résoluble (id absent/non-numérique).
     *
     * @param  array<int, array<string, mixed>>  $matieres
     * @param  \Closure(array<string, mixed>): (int|null)  $idResolver
     * @return array<int, array<string, mixed>>
     */
    private function keyByMatiereId(array $matieres, \Closure $idResolver): array
    {
        $byId = [];
        foreach ($matieres as $matiere) {
            $id = $idResolver($matiere);
            if ($id !== null) {
                $byId[$id] = $matiere;
            }
        }

        return $byId;
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
    private function studentMatiereId(array $matiere): ?int
    {
        $nestedMatiere = $matiere['matiere'] ?? null;
        $nestedId = is_array($nestedMatiere) ? ($nestedMatiere['id'] ?? null) : null;

        return KlassciPayload::toInt($matiere['id'] ?? $matiere['matiere_id'] ?? $nestedId);
    }
}
