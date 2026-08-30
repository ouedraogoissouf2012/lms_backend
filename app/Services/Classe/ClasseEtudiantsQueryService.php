<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\User;
use App\Services\KlassciProxyService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ClasseEtudiantsQueryService — liste roster des étudiants d'une classe.
 *
 * Extrait de {@see \App\Http\Controllers\API\LMS\LMSClassesController::classeEtudiants()}
 * (split-20/lms-classes) afin de respecter §1.1/§5 (controllers et services
 * bornés en taille).
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
 * Aucune Facade. {@see KlassciProxyService} et PSR-3 {@see LoggerInterface}
 * injectés par constructeur.
 *
 * ## Contrat de retour
 *
 * Tuple `array{status: int, payload: array<string, mixed>}` :
 *   - `200` + `{success: true, data: {etudiants, total}}` ;
 *   - `401` si pas de token KLASSCI ;
 *   - `500` sur exception inattendue (message générique, détail loggué).
 *
 * @see app/Http/Controllers/API/LMS/LMSClassesController.php
 */
final class ClasseEtudiantsQueryService
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Récupère la liste des étudiants d'une classe pour un utilisateur authentifié.
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function getEtudiants(int $classeId, User $user): array
    {
        $klassciToken = $user->klassci_token;

        if (!$klassciToken) {
            return [
                'status' => 401,
                'payload' => [
                    'success' => false,
                    'message' => MissingKlassciTokenException::CLIENT_MESSAGE,
                ],
            ];
        }

        try {
            $this->logger->info('Récupération étudiants classe', [
                'classe_id' => $classeId,
                'user_id' => $user->id,
            ]);

            $etudiantsResponse = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "classes/{$classeId}/etudiants",
                'GET'
            );

            /** @var array<int, array<string, mixed>> $etudiants */
            $etudiants = $etudiantsResponse['data'] ?? [];

            // Filtre permissif : un étudiant sans champ `statut` est inclus
            // (legacy rows). Cf. docblock du service pour le rationale.
            $etudiantsActifs = collect($etudiants)
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
        } catch (Throwable $e) {
            $this->logger->error('Erreur récupération étudiants classe', [
                'classe_id' => $classeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de la récupération des étudiants',
                    'error' => 'Une erreur est survenue.',
                ],
            ];
        }
    }
}
