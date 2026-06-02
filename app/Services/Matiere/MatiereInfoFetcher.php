<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Services\KlassciProxyService;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * MatiereInfoFetcher — fetches the base matière payload from KLASSCI.
 *
 * Extracted from {@see \App\Http\Controllers\API\LMS\LMSMatieresQueryController::matiereDetails}
 * (legacy lines 56-130).
 *
 * Responsibility:
 *   - Calls `matieres/{id}` KLASSCI endpoint.
 *   - Normalises the response shape (`{matiere, combinaisons, enseignants}`).
 *   - Falls back to fetching enseignants via the emploi-temps endpoint when
 *     the base payload omits them.
 *
 * Behavioural contract preserved from the original controller:
 *   - Returns `null` when KLASSCI returns no `data` (caller renders 404).
 *   - Re-throws any other KLASSCI exception (caller renders 500).
 *
 * @see PRODUCTION_STANDARDS.md §1.1 (services ≤300 lignes)
 */
final class MatiereInfoFetcher
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Fetch the matière + its combinaisons + enseignants.
     *
     * @return array{matiere: array<string, mixed>, matiereData: array<string, mixed>, combinaisons: array<int, array<string, mixed>>, enseignants: array<int, array<string, mixed>>}|null
     */
    public function fetchMatiereInfo(string $klassciToken, int $matiereId): ?array
    {
        $matiereResponse = $this->klassciService->requestWithUserToken(
            $klassciToken,
            "matieres/{$matiereId}",
            'GET'
        );

        /** @var array<string, mixed>|null $matiereData */
        $matiereData = $matiereResponse['data'] ?? null;

        if (!is_array($matiereData) || $matiereData === []) {
            return null;
        }

        // KLASSCI returns {data: {matiere: {...}, combinaisons: [...], ...}}.
        /** @var array<string, mixed> $matiere */
        $matiere = is_array($matiereData['matiere'] ?? null)
            ? $matiereData['matiere']
            : $matiereData;

        $this->logger->info('Structure matière KLASSCI', [
            'has_matiere_key' => isset($matiereData['matiere']),
            'matiere_nom' => $matiere['nom'] ?? 'N/A',
        ]);

        /** @var array<int, array<string, mixed>> $combinaisons */
        $combinaisons = is_array($matiereData['combinaisons'] ?? null)
            ? $matiereData['combinaisons']
            : [];

        // KLASSCI may put enseignants in matiereData OR inside matiere.
        /** @var array<int, array<string, mixed>> $enseignants */
        $enseignants = is_array($matiereData['enseignants'] ?? null)
            ? $matiereData['enseignants']
            : (is_array($matiere['enseignants'] ?? null) ? $matiere['enseignants'] : []);

        if ($enseignants === []) {
            $enseignants = $this->fetchEnseignantsViaEmploiTemps($klassciToken, $matiereId);
        }

        return [
            'matiere' => $matiere,
            'matiereData' => $matiereData,
            'combinaisons' => $combinaisons,
            'enseignants' => $enseignants,
        ];
    }

    /**
     * Fallback enseignants resolver: extract unique teachers from emploi-temps.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchEnseignantsViaEmploiTemps(string $klassciToken, int $matiereId): array
    {
        try {
            $dateDebut = Carbon::now()->format('Y-m-d');
            $dateFin = Carbon::now()->addDays(30)->format('Y-m-d');

            $emploiTempsResponse = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "emploi-temps?matiere_id={$matiereId}&date_debut={$dateDebut}&date_fin={$dateFin}",
                'GET'
            );

            /** @var array<int, array<string, mixed>> $seancesEmploi */
            $seancesEmploi = $emploiTempsResponse['data'] ?? [];

            /** @var array<int, array<string, mixed>> $enseignants */
            $enseignants = collect($seancesEmploi)
                ->pluck('enseignant')
                ->filter()
                ->unique('id')
                ->values()
                ->toArray();

            return $enseignants;
        } catch (Throwable $e) {
            $this->logger->warning('Erreur récupération enseignants via emploi du temps', [
                'matiere_id' => $matiereId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
