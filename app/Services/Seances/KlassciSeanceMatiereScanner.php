<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Services\KlassciProxyService;

/**
 * Résout, pour un ensemble de matières candidates (role-specific), la
 * matière qui contient une séance donnée.
 *
 * Fast-path résolution locale (1 HTTP) puis fallback batch en pool — issue
 * #517, remplace les 3 boucles HTTP séquentielles auparavant dupliquées dans
 * {@see KlassciSeanceLookupService} (une par rôle : teacher/student/coordinateur).
 *
 * @see LocalSeanceMatiereResolver — résolution locale (colonne indexée)
 */
final class KlassciSeanceMatiereScanner
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly LocalSeanceMatiereResolver $localMatiereResolver,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $matieresById  matiereId => item de la liste candidate (role-specific)
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>, 2: array<string, mixed>} `[seance, matiereDetails, matiereFallback]`
     */
    public function scan(array $matieresById, int $seanceId, string $klassciToken): array
    {
        $fastPath = $this->tryLocalFastPath($matieresById, $seanceId, $klassciToken);
        if ($fastPath !== null) {
            return $fastPath;
        }

        $candidateIds = array_keys($matieresById);
        $detailsMap = $candidateIds === []
            ? []
            : $this->klassciService->fetchManyMatieresDetails($candidateIds, $klassciToken);

        foreach ($matieresById as $matiereId => $matiereFallback) {
            $matiereDetails = $detailsMap[$matiereId] ?? null;
            if ($matiereDetails === null) {
                continue;
            }

            $seanceTrouvee = $this->findSeance($matiereDetails, $seanceId);
            if ($seanceTrouvee !== null) {
                return [$seanceTrouvee, $matiereDetails, $matiereFallback];
            }
        }

        return [null, [], []];
    }

    /**
     * Résolution locale (colonne indexée `seances.klassci_matiere_id`) : si la
     * séance est déjà synchronisée ET que sa matière fait partie de l'ensemble
     * accessible par le rôle courant (sécurité — évite tout IDOR via une
     * donnée locale désynchronisée), un seul GET ciblé remplace le scan
     * complet. `null` si non applicable — y compris sur échec du GET ciblé
     * (timeout, matière réassignée/supprimée côté KLASSCI) : le caller
     * retombe alors sur le fallback batch, aussi résilient aux échecs
     * partiels que le scan complet ({@see KlassciBatchFetcher}).
     *
     * @param  array<int, array<string, mixed>>  $matieresById  matiereId => item de la liste candidate (role-specific)
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>, 2: array<string, mixed>}|null
     */
    private function tryLocalFastPath(array $matieresById, int $seanceId, string $klassciToken): ?array
    {
        if ($matieresById === []) {
            return null;
        }

        $localMatiereId = $this->localMatiereResolver->matiereIdFor($seanceId);
        if ($localMatiereId === null || ! array_key_exists($localMatiereId, $matieresById)) {
            return null;
        }

        try {
            $matiereDetails = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "matieres/{$localMatiereId}",
                'GET'
            );
        } catch (\Exception) {
            return null;
        }

        $seanceTrouvee = $this->findSeance($matiereDetails, $seanceId);

        return $seanceTrouvee !== null
            ? [$seanceTrouvee, $matiereDetails, $matieresById[$localMatiereId]]
            : null;
    }

    /**
     * @param  array<string, mixed>  $matiereDetails
     * @return array<string, mixed>|null
     */
    private function findSeance(array $matiereDetails, int $seanceId): ?array
    {
        $seancesProgrammees = KlassciPayload::listOfArrays(
            KlassciPayload::asArray($matiereDetails['data'] ?? null)['seances_programmees'] ?? null
        );

        foreach ($seancesProgrammees as $seance) {
            if (KlassciPayload::toInt($seance['id'] ?? null) === $seanceId) {
                return $seance;
            }
        }

        return null;
    }
}
