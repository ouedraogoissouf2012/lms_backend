<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\User;
use Psr\Log\LoggerInterface;

/**
 * ClasseEtudiantsQueryService — liste roster des étudiants d'une classe.
 *
 * Extrait de {@see \App\Http\Controllers\API\LMS\LMSClassesController::classeEtudiants()}
 * (split-20/lms-classes) afin de respecter §1.1/§5 (controllers et services
 * bornés en taille).
 *
 * ## La source : l'enveloppe, via {@see ClasseRoster}
 *
 * KLASSCI applique une autorisation PAR CLASSE sur `classes/{id}/etudiants` et
 * répond 403 à TOUS les rôles (mesuré en #669). Ce service était le dernier
 * lecteur resté sur cet appel : la route rendait un 500 permanent, que l'écran
 * affichait « 0 étudiant ». Le roster est lu dans l'enveloppe `classes/{id}`,
 * par le même composant que les résultats d'évaluation.
 *
 * ## Différence subtile vs. {@see ClasseDetailsQueryService}
 *
 * Ce service applique un filtre **permissif** : un étudiant sans champ
 * `statut` est inclus (`!isset || === 'actif'`), alors que la requête
 * « détails classe » applique un filtre **strict** (`isset && === 'actif'`).
 * Les deux comportements sont conservés verbatim depuis le legacy
 * `LMSDataController` — l'endpoint roster est traité comme canonique et tolère
 * les rows héritées sans `statut`, tandis que les stats du dashboard détails
 * doivent rester strictes.
 *
 * ## DI strict (§1.6 D)
 *
 * Aucune Facade. {@see ClasseRoster} et PSR-3 {@see LoggerInterface}
 * injectés par constructeur.
 *
 * ## Contrat de retour
 *
 * Tuple `array{status: int, payload: array<string, mixed>}` :
 *   - `200` + `{success: true, data: {etudiants, total}}` ;
 *   - `401` si pas de token KLASSCI.
 *
 * Un refus ou une indisponibilité de KLASSCI n'est PAS rattrapé ici : il remonte
 * en `RuntimeException`, que le contrôleur rend par
 * {@see \App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors} (4xx relayé
 * avec son motif, panne en 503 + `Retry-After`). L'ancien `catch (Throwable)`
 * les écrasait en 500 générique.
 *
 * @see app/Http/Controllers/API/LMS/LMSClassesController.php
 */
final class ClasseEtudiantsQueryService
{
    public function __construct(
        private readonly ClasseRoster $roster,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Récupère la liste des étudiants d'une classe pour un utilisateur authentifié.
     *
     * @return array{status: int, payload: array<string, mixed>}
     *
     * @throws \RuntimeException refus (4xx) ou indisponibilité de KLASSCI.
     */
    public function getEtudiants(int $classeId, User $user): array
    {
        $klassciToken = $user->klassci_token;

        if (!is_string($klassciToken) || $klassciToken === '') {
            return [
                'status' => 401,
                'payload' => MissingKlassciTokenException::payload(),
            ];
        }

        $this->logger->info('Récupération étudiants classe', [
            'classe_id' => $classeId,
            'user_id' => $user->id,
        ]);

        // Filtre permissif : un étudiant sans champ `statut` est inclus
        // (legacy rows). Cf. docblock du service pour le rationale.
        $etudiantsActifs = collect($this->roster->etudiants($classeId, $klassciToken))
            ->filter(static function (array $etudiant): bool {
                return !isset($etudiant['statut']) || $etudiant['statut'] === 'actif';
            })
            ->values()
            ->all();

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'data' => [
                    'etudiants' => $etudiantsActifs,
                    'total' => count($etudiantsActifs),
                ],
            ],
        ];
    }
}
