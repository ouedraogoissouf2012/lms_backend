<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors;
use App\Http\Controllers\AuthenticatedController;
use App\Services\Matiere\MatiereDetailsReader;
use App\Services\Matiere\TeacherMatieresReader;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * LMSMatieresQueryController — read endpoints for matières.
 *
 * Thin HTTP layer (§5 ≤200 lignes). Business pipeline lives in:
 *   - {@see MatiereDetailsReader} (/matieres/{id})
 *   - {@see TeacherMatieresReader} (teacher dashboard /teacher/my-matieres)
 *
 * Each reader has two sources, KLASSCI and local, chosen at the binding point.
 * They are injected per METHOD, never through the constructor: Laravel keeps
 * the controller on the Route object, and a source received at construction
 * would stay the one of the first school served.
 *
 * A `QueryException` is a `RuntimeException` (through `PDOException`): without
 * its own branch, a database failure fell into `renderKlassciFailure`, was
 * rendered as a KLASSCI error and was NEVER logged. On the local source every
 * failure is a database failure. It now takes the logged internal branch.
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
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * GET /api/lms/matieres/{id}
     * Retourne les détails complets d'une matière.
     */
    public function matiereDetails(int $matiereId, Request $request, MatiereDetailsReader $details): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            $this->logger->info('Récupération détails matière', [
                'matiere_id' => $matiereId,
                'user_id' => $user->id,
            ]);

            $data = $details->getDetailsForUser($matiereId, $user);

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
        } catch (QueryException $e) {
            return $this->echecDetails($matiereId, $e);
        } catch (RuntimeException $e) {
            return $this->renderKlassciFailure($e);
        } catch (Throwable $e) {
            return $this->echecDetails($matiereId, $e);
        }
    }

    /**
     * GET /api/lms/teacher/my-matieres
     * Retourne les matières de l'enseignant connecté avec statistiques enrichies.
     */
    public function myMatieres(Request $request, TeacherMatieresReader $matieres): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            return $this->successResponse($matieres->getMatieresForUser($user));
        } catch (QueryException $e) {
            return $this->echecMesMatieres($e);
        } catch (RuntimeException $e) {
            return $this->renderKlassciFailure($e);
        } catch (Throwable $e) {
            return $this->echecMesMatieres($e);
        }
    }

    private function echecDetails(int $matiereId, Throwable $e): JsonResponse
    {
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

    private function echecMesMatieres(Throwable $e): JsonResponse
    {
        // §1.2 — Detail logged server-side, generic message to client.
        $this->logger->error('Erreur myMatieres', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return $this->errorResponse('Erreur lors du chargement des matières.', 500);
    }
}
