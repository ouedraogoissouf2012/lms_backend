<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS\Concerns;

/**
 * Trait extrait du privé `getSeanceDataFromKlassci()` lors du split du
 * god-controller `LMSSeancesController` (PR #154). Son seul hôte est
 * aujourd'hui {@see \App\Services\Visio\VisioToggleService}.
 *
 * Trait plutôt que service dédié : la méthode dépend de `$this->klassciService`
 * et de `$this->journal` (`CaughtExceptionLog`), injectés par l'hôte ; elle
 * garde la signature `private` et réutilise telle quelle l'implémentation
 * legacy.
 *
 * À promouvoir en service `SeanceKlassciFetcher` dans une PR ultérieure
 * si d'autres controllers en ont besoin.
 */
trait FetchesSeanceDataFromKlassci
{
    /**
     * Trouve la séance dans les matières de l'enseignant et retourne ses métadonnées
     * (matière, classe, enseignant). Retourne `null` si introuvable ou si KLASSCI down.
     *
     * @return array<string, mixed>|null
     */
    private function getSeanceDataFromKlassci(int $seanceId, string $klassciToken): ?array
    {
        try {
            // Récupérer toutes les matières de l'enseignant
            $matieres = $this->klassciService->requestWithUserToken($klassciToken, 'matieres', 'GET');

            foreach ($matieres['data'] ?? [] as $matiere) {
                // Récupérer les détails de chaque matière
                $details = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "matieres/{$matiere['id']}",
                    'GET'
                );

                $seances = $details['data']['seances_programmees'] ?? [];

                // Chercher la séance
                foreach ($seances as $seance) {
                    if ($seance['id'] == $seanceId) {
                        // Séance trouvée!
                        return [
                            'matiere_id'     => $matiere['id'],
                            'matiere_nom'    => $matiere['nom'] ?? $matiere['libelle'] ?? null,
                            'classe_id'      => $seance['classe']['id'] ?? null,
                            'classe_nom'     => $seance['classe']['nom'] ?? null,
                            'enseignant_id'  => $details['data']['enseignant']['id'] ?? null,
                            'enseignant_nom' => $details['data']['enseignant']['nom_complet'] ?? null,
                        ];
                    }
                }
            }

            return null;

        } catch (\Exception $e) {
            $this->journal->error('Erreur récupération données séance Klassci', $e, [
                'seance_id' => $seanceId,
            ]);
            return null;
        }
    }
}
