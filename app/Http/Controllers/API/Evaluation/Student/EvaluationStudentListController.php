<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Evaluation\Student;

use App\Http\Controllers\AuthenticatedController;
use App\Services\Evaluation\Student\StudentEvaluationsListService;
use App\Services\Evaluation\Student\StudentGradesAggregator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Lectures étudiant : liste enrichie de ses évaluations + ses notes par
 * matière. Extrait de `EvaluationStudentController` (split §5).
 *
 * Endpoints :
 *   - GET /api/evaluations/student/my-evaluations
 *   - GET /api/evaluations/student/my-grades
 */
final class EvaluationStudentListController extends AuthenticatedController
{
    public function __construct(
        private readonly StudentEvaluationsListService $listService,
        private readonly StudentGradesAggregator $gradesAggregator,
    ) {}

    public function myEvaluations(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if (!$user->klassci_id) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur sans ID KLASSCI synchronisé',
            ], 401);
        }

        try {
            $data = $this->listService->getEnrichedEvaluationsForStudent($user);
            if ($data === null) {
                return response()->json(['success' => false, 'message' => 'Classe non trouvée'], 404);
            }
            return response()->json(['success' => true, 'data' => $data]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.',
            ], 401);
        } catch (\Exception $e) {
            Log::error('Erreur récupération évaluations étudiant', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des évaluations',
            ], 500);
        }
    }

    public function myGrades(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            if (!$user->klassci_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur sans ID KlassCI synchronisé',
                ], 401);
            }

            $data = $this->gradesAggregator->getGradesAggregatedByMatiere($user);
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            Log::error('Erreur récupération notes étudiant', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des notes',
                'error' => 'Une erreur est survenue.',
            ], 500);
        }
    }
}
