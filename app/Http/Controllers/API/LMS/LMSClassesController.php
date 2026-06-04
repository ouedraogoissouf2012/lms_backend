<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Services\Classe\ClasseDetailsQueryService;
use App\Services\Classe\ClasseEtudiantsQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * LMS Classes — détails et étudiants (thin controller).
 *
 * Extracted from `LMSDataController` (god-object split — spec
 * `.claude/specs/lms-data-controller-split/`) puis splitté en services
 * SRP lors de `split-20/lms-classes` afin de respecter §5 (controllers
 * ≤200 lignes) et §1.1 (services ≤300 lignes).
 *
 * Responsibilities :
 *   - GET /api/lms/classes/{classeId}              → classeDetails()
 *   - GET /api/lms/classes/{classeId}/etudiants    → classeEtudiants()
 *
 * Toute la logique d'orchestration KLASSCI (5 appels API, filtres, stats,
 * dégradation gracieuse par bloc) est déléguée à :
 *   - {@see ClasseDetailsQueryService}  — endpoint « détails ».
 *   - {@see ClasseEtudiantsQueryService} — endpoint « roster étudiants ».
 *
 * Le controller se borne à : auth + délégation + sérialisation JSON +
 * filet de sécurité 500 pour les exceptions imprévues.
 */
final class LMSClassesController extends AuthenticatedController
{
    public function __construct(
        private readonly ClasseDetailsQueryService $detailsService,
        private readonly ClasseEtudiantsQueryService $etudiantsService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * GET /api/lms/classes/{id}
     * Retourne les détails complets d'une classe (infos, étudiants actifs,
     * matières, emploi du temps de la semaine, évaluations programmées, stats).
     */
    public function classeDetails(int $classeId, Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $result = $this->detailsService->getDetailsForUser($classeId, $user);

            return response()->json($result['payload'], $result['status']);
        } catch (Throwable $e) {
            // §1.2 — message générique au client, détail loggué côté serveur.
            $this->logger->error('Erreur récupération détails classe', [
                'classe_id' => $classeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des détails de la classe',
                'error' => 'Une erreur est survenue.',
            ], 500);
        }
    }

    /**
     * GET /api/lms/classes/{id}/etudiants
     * Retourne la liste des étudiants d'une classe (filtre permissif —
     * cf. {@see ClasseEtudiantsQueryService}).
     */
    public function classeEtudiants(int $classeId, Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $result = $this->etudiantsService->getEtudiants($classeId, $user);

            return response()->json($result['payload'], $result['status']);
        } catch (Throwable $e) {
            $this->logger->error('Erreur récupération étudiants classe', [
                'classe_id' => $classeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des étudiants',
                'error' => 'Une erreur est survenue.',
            ], 500);
        }
    }
}
