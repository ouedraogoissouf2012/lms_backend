<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors;
use App\Http\Controllers\AuthenticatedController;
use App\Services\Matiere\MatiereDetailsQueryService;
use App\Services\Matiere\MyMatieresQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * LMSMatieresQueryController — read endpoints for matières.
 *
 * Thin HTTP layer (§5 ≤200 lignes). Business pipeline lives in:
 *   - {@see MatiereDetailsQueryService} (orchestrates 4 sub-fetchers for /matieres/{id})
 *   - {@see MyMatieresQueryService} (teacher dashboard /teacher/my-matieres)
 *
 * History: split from the 603-line legacy LMSMatieresQueryController
 * (itself extracted earlier from the 774-line LMSMatieresController god).
 *
 * @see PRODUCTION_STANDARDS.md §5 (controllers ≤200 lignes)
 * @see PRODUCTION_STANDARDS.md §1.6 D (DI strict)
 */
final class LMSMatieresQueryController extends AuthenticatedController
{
    use RendersKlassciBackedErrors;

    public function __construct(
        private readonly MatiereDetailsQueryService $detailsService,
        private readonly MyMatieresQueryService $myMatieresService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * GET /api/lms/matieres/{id}
     * Retourne les détails complets d'une matière.
     */
    public function matiereDetails(int $matiereId, Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            $this->logger->info('Récupération détails matière', [
                'matiere_id' => $matiereId,
                'user_id' => $user->id,
            ]);

            $data = $this->detailsService->getDetailsForUser($matiereId, $user);

            if ($data === null) {
                return $this->errorResponse('Matière non trouvée', 404);
            }

            $this->logger->info('✅ Matière details response', [
                'matiere_id' => $matiereId,
                'has_matiere' => !empty($data['matiere']),
                'lessons_count' => count($data['lessons']),
                'seances_count' => count($data['seances_programmees']),
                'evaluations_count' => count($data['evaluations_programmees']),
            ]);

            return $this->successResponse($data);
        } catch (RuntimeException $e) {
            return $this->renderKlassciFailure($e);
        } catch (Throwable $e) {
            $this->logger->error('Erreur récupération détails matière', [
                'matiere_id' => $matiereId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Réponse NON migrée : la clé racine `error` (hors enveloppe standard)
            // ne peut pas être reproduite par errorResponse() — DRY-only.
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des détails de la matière',
                'error' => 'Une erreur est survenue.',
            ], 500);
        }
    }

    /**
     * GET /api/lms/teacher/my-matieres
     * Retourne les matières de l'enseignant connecté avec statistiques enrichies.
     */
    public function myMatieres(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $matieres = $this->myMatieresService->getMatieresForUser($user);

            return $this->successResponse($matieres);
        } catch (RuntimeException $e) {
            return $this->renderKlassciFailure($e);
        } catch (Throwable $e) {
            // §1.2 — Detail logged server-side, generic message to client.
            $this->logger->error('Erreur myMatieres', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Erreur lors du chargement des matières.', 500);
        }
    }
}
