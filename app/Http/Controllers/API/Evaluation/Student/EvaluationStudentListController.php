<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Evaluation\Student;

use App\Exceptions\MissingKlassciTokenException;
use App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors;
use App\Http\Controllers\AuthenticatedController;
use App\Services\Evaluation\Student\StudentEvaluationsListService;
use App\Services\Evaluation\Student\StudentGradesAggregator;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    use RendersKlassciBackedErrors;

    public function __construct(
        private readonly StudentEvaluationsListService $listService,
        private readonly StudentGradesAggregator $gradesAggregator,
        private readonly CaughtExceptionLog $journal,
    ) {}

    public function myEvaluations(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        // #806 : sans identité KLASSCI (élève local, école autonome), aucun jeton
        // n'existe non plus. Même refus, même motif que le jeton absent.
        if (!$user->klassci_id) {
            return MissingKlassciTokenException::jsonResponse();
        }

        try {
            $data = $this->listService->getEnrichedEvaluationsForStudent($user);
            if ($data === null) {
                return $this->errorResponse('Classe non trouvée', 404);
            }
            return $this->successResponse($data);
        } catch (RuntimeException $e) {
            return $this->renderKlassciFailure($e);
        } catch (\Exception $e) {
            $this->journal->error('Erreur récupération évaluations étudiant', $e);
            return $this->errorResponse('Erreur lors de la récupération des évaluations', 500);
        }
    }

    public function myGrades(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            if (!$user->klassci_id) {
                return MissingKlassciTokenException::jsonResponse();
            }

            $data = $this->gradesAggregator->getGradesAggregatedByMatiere($user);
            return $this->successResponse($data);
        } catch (\Exception $e) {
            $this->journal->error('Erreur récupération notes étudiant', $e, [
                'user_id' => $request->user()?->id,
            ]);
            // Non migré vers errorResponse() : cette réponse expose une clé racine
            // `error` (singulier, string) que le trait ne reproduit pas — il ne
            // produit que `errors` (pluriel, tableau). La migrer changerait le
            // contrat client → conservé tel quel (axe #1 « DRY-only »).
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des notes',
                'error' => 'Une erreur est survenue.',
            ], 500);
        }
    }
}
