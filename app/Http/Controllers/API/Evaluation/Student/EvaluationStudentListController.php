<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Evaluation\Student;

use App\Exceptions\MissingKlassciTokenException;
use App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors;
use App\Http\Controllers\AuthenticatedController;
use App\Services\Evaluation\Student\StudentEvaluationsReader;
use App\Services\Evaluation\Student\MatieresDesNotes;
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
        private readonly StudentGradesAggregator $gradesAggregator,
        private readonly CaughtExceptionLog $journal,
    ) {}

    /**
     * D72, E2a : la liste vient de la source de l'école, reçue par la MÉTHODE
     * (ADR-EI-01, règle 3). L'identité KLASSCI n'est exigée que par la source
     * KLASSCI (#806 : même refus, même motif que le jeton absent).
     */
    public function myEvaluations(Request $request, StudentEvaluationsReader $lecture): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        try {
            $data = $lecture->evaluationsFor($user);
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

    /**
     * Les matières viennent de la source de l'établissement (D72, E4a), reçue par
     * la MÉTHODE : Laravel garde le contrôleur sur l'objet Route.
     */
    public function myGrades(Request $request, MatieresDesNotes $matieres): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            if ($matieres->refuse($user)) {
                return MissingKlassciTokenException::jsonResponse();
            }

            $data = $this->gradesAggregator->getGradesAggregatedByMatiere($user, $matieres);
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
