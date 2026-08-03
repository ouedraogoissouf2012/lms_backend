<?php

declare(strict_types=1);

namespace App\Services\Seances;

use Illuminate\Support\Collection;

/**
 * Projette les séances programmées KLASSCI d'une matière vers la forme de sortie
 * legacy attendue par le frontend (structure `programmation` + `matiere` +
 * `classe`), en réalignant les datetimes de programmation sur la date de séance.
 *
 * ## Issue #476 — extrait de {@see UpcomingSeancesFetcher} (§1.1 ≤300 lignes)
 *
 * Data-mapping pur (aucune I/O), extrait pour faire repasser
 * `UpcomingSeancesFetcher` sous la limite de 300 lignes après l'ajout du
 * pré-chargement anti-N+1. Injecté par constructeur (pattern MANIFESTE_REFACTORING).
 */
final class UpcomingSeanceMapper
{
    /**
     * @param  Collection<int, array<string, mixed>>  $seances
     * @param  array<string, mixed>  $matiere
     * @return Collection<int, array<string, mixed>>
     */
    public function map(Collection $seances, array $matiere): Collection
    {
        // IMPORTANT: Le frontend attend seance.programmation.date, pas seance.date_seance.
        /** @var Collection<int, array<string, mixed>> $mapped */
        $mapped = $seances->map(function (array $seance) use ($matiere): array {
            $prog = KlassciPayload::asArray($seance['programmation'] ?? null);
            $classe = KlassciPayload::asArray($seance['classe'] ?? null);
            $date = KlassciPayload::toStringOrNull($prog['date'] ?? null);

            return [
                'id' => $seance['id'] ?? null,
                'programmation' => [
                    'date' => $date,
                    // KLASSCI date heure_debut/heure_fin au jour courant → réaligner
                    // sur la date réelle de la séance.
                    'heure_debut' => SeanceProgrammationNormalizer::alignDate(
                        KlassciPayload::toStringOrNull($prog['heure_debut'] ?? null),
                        $date
                    ),
                    'heure_fin' => SeanceProgrammationNormalizer::alignDate(
                        KlassciPayload::toStringOrNull($prog['heure_fin'] ?? null),
                        $date
                    ),
                    'salle' => $prog['salle'] ?? null,
                ],
                'salle' => $prog['salle'] ?? null, // Aussi en racine pour compatibilité
                'matiere' => [
                    'id' => $matiere['id'] ?? null,
                    'libelle' => $matiere['nom'] ?? $matiere['libelle'] ?? 'N/A', // KLASSCI utilise 'nom'
                    'code' => $matiere['code'] ?? null,
                ],
                'classe' => [
                    'id' => $classe['id'] ?? null,
                    'libelle' => $classe['nom'] ?? 'N/A',
                ],
                // `enseignant` intentionnellement null : KLASSCI ne le fournit pas à ce
                // niveau ; résolu côté frontend via /enseignants/{matiere_id} si besoin.
                'enseignant' => null,
            ];
        });

        return $mapped;
    }
}
