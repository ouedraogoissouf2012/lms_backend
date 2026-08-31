<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Services\KlassciProxyService;

/**
 * Liste des matières enrichies pour l'écran d'administration.
 *
 * ## Pourquoi un seul appel amont
 *
 * Cet écran demandait à KLASSCI le détail de CHAQUE matière pour en extraire
 * `combinaisons` : 1 + N appels, soit 453 sur l'établissement de référence.
 * Mesuré à ~601 ms l'appel, la réponse n'arrivait jamais (~272 s projetées,
 * pour un plafond client de 30 s).
 *
 * Or `GET matieres` porte déjà `combinaisons` pour chaque matière — vérifié
 * contre `matieres/{id}` : mêmes éléments, seul l'ordre diffère. Le détail
 * n'apportait donc rien que la liste n'ait déjà. Même geste que pour le roster
 * de classe (#669) : lire la donnée là où elle est déjà livrée.
 *
 * ## Ce qui est mesuré et ce qui ne l'est pas
 *
 * La liste porte les heures (`heures.total`) et le compte d'évaluations
 * (`lms_metadata.total_evaluations`). Elle ne porte NI le nombre de séances
 * programmées NI le nombre de leçons : ces deux-là valent `null` (à rendre
 * « — »), jamais `0` — un zéro se lirait comme un comptage, alors que la donnée
 * n'a simplement pas été demandée. Les rétablir coûterait le N+1 supprimé ici.
 *
 * @see app/Http/Controllers/API/LMS/LMSMatieresAdminController.php
 */
final class AdminMatieresQueryService
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
    ) {}

    /**
     * @return array{matieres: array<int, array<string, mixed>>, statistiques: array<string, mixed>}
     */
    public function listForAdmin(string $klassciToken): array
    {
        $response = $this->klassciService->requestWithUserToken($klassciToken, 'matieres', 'GET');

        /** @var array<int, mixed> $raw */
        $raw = is_array($response['data'] ?? null) ? $response['data'] : [];

        $matieres = [];
        foreach ($raw as $matiere) {
            if (is_array($matiere)) {
                $matieres[] = $this->present($matiere);
            }
        }

        return [
            'matieres' => $matieres,
            'statistiques' => $this->statistics($matieres),
        ];
    }

    /**
     * Projection d'une matière KLASSCI vers le contrat de l'écran admin.
     *
     * @param  array<mixed, mixed>  $matiere  Cles non garanties : payload JSON amont.
     * @return array<string, mixed>
     */
    private function present(array $matiere): array
    {
        $heures = is_array($matiere['heures'] ?? null) ? $matiere['heures'] : [];
        $metadata = is_array($matiere['lms_metadata'] ?? null) ? $matiere['lms_metadata'] : [];

        return [
            'id' => $matiere['id'] ?? null,
            'nom' => $matiere['nom'] ?? 'N/A',
            'code' => $matiere['code'] ?? 'N/A',
            'description' => $matiere['description'] ?? null,
            'coefficient' => $matiere['coefficient'] ?? null,
            'couleur' => $matiere['couleur'] ?? '#6366f1',
            'heures_total' => $this->intOrNull($heures['total'] ?? null),
            'nb_evaluations' => $this->intOrNull($metadata['total_evaluations'] ?? null),
            // Absents de la liste : la valeur n'est pas connue, elle n'est pas nulle.
            'nb_seances_programmees' => null,
            'nb_lecons' => null,
            'combinaisons' => $this->combinaisons($matiere),
        ];
    }

    /**
     * Combinaisons filière+niveau livrées avec la matière.
     *
     * @param  array<mixed, mixed>  $matiere  Cles non garanties : payload JSON amont.
     * @return array<int, array<string, mixed>>
     */
    private function combinaisons(array $matiere): array
    {
        if (! is_array($matiere['combinaisons'] ?? null)) {
            return [];
        }

        $combinaisons = [];
        foreach ($matiere['combinaisons'] as $combinaison) {
            if (is_array($combinaison)) {
                $combinaisons[] = $combinaison;
            }
        }

        return $combinaisons;
    }

    /**
     * Totaux : on ne somme que ce qui a été réellement mesuré.
     *
     * `total_heures` vaut `null` si AUCUNE matière ne porte d'heures — sommer des
     * absences donnerait `0`, indiscernable d'un vrai « zéro heure ». `total_seances`
     * est toujours `null` : la liste ne porte pas cette donnée (cf. en-tête).
     *
     * @param  array<int, array<string, mixed>>  $matieres
     * @return array<string, mixed>
     */
    private function statistics(array $matieres): array
    {
        $heures = [];
        foreach ($matieres as $matiere) {
            if (is_int($matiere['heures_total'])) {
                $heures[] = $matiere['heures_total'];
            }
        }

        return [
            'total' => count($matieres),
            'total_heures' => $matieres === [] ? 0 : ($heures === [] ? null : array_sum($heures)),
            'total_seances' => null,
        ];
    }

    /** Entier KLASSCI, ou `null` si la valeur est absente ou non numérique. */
    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
