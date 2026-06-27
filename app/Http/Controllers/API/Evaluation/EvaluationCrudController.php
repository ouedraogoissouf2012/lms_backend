<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Evaluation;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\DeleteEvaluationRequest;
use App\Http\Requests\PublishEvaluationRequest;
use App\Http\Requests\StoreEvaluationRequest;
use App\Http\Requests\UpdateEvaluationRequest;
use App\Models\Evaluation;
use App\Services\Evaluation\EvaluationCreationService;
use App\Services\Evaluation\EvaluationListService;
use App\Services\Evaluation\EvaluationStateService;
use App\Services\Evaluation\EvaluationUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller for evaluation CRUD endpoints.
 *
 * Délègue toute la logique métier à des services SRP dédiés (§1.1 ≤300 l) —
 * split du précédent god-controller post-PR #149 puis re-split §5 du
 * monolithe (408 lignes → 4 services + controller ≤200 lignes).
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * Tous les services sont injectés par constructeur, jamais via `app()`.
 * Les services dépendent eux-mêmes de PSR-3 `LoggerInterface` plutôt que
 * de la Facade `Log::`.
 *
 * ## Endpoints
 *
 * - `GET    /api/evaluations`               → {@see index}
 * - `GET    /api/evaluations/{id}`          → {@see show}
 * - `POST   /api/evaluations`               → {@see store}
 * - `PUT    /api/evaluations/{id}`          → {@see update}
 * - `DELETE /api/evaluations/{id}`          → {@see destroy}
 * - `POST   /api/evaluations/{id}/publish`  → {@see publish}
 */
final class EvaluationCrudController extends AuthenticatedController
{
    public function __construct(
        private readonly EvaluationListService $listService,
        private readonly EvaluationCreationService $creationService,
        private readonly EvaluationUpdateService $updateService,
        private readonly EvaluationStateService $stateService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        $filters = $request->only(['classe_id', 'matiere_id', 'status', 'is_published']);

        $evaluations = $this->listService->listForTeacher($user, $filters);

        return $this->successResponse($evaluations);
    }

    public function show(int $id): JsonResponse
    {
        $enriched = $this->listService->findOne($id);

        if ($enriched === null) {
            return $this->errorResponse('Évaluation non trouvée', 404);
        }

        return $this->successResponse($enriched);
    }

    public function store(StoreEvaluationRequest $request): JsonResponse
    {
        // FormRequest handles authorization (role check).

        // Issue #124 — sécurité : l'identité enseignant est dérivée du token
        // Sanctum, jamais du body. Un utilisateur sans `klassci_enseignant_id`
        // synchronisé (admin LMS local, compte service) n'a pas vocation à
        // créer d'évaluation — refus explicite ici plutôt qu'un check
        // d'ownership qui échouerait plus tard.
        $user = $this->authenticatedUser($request);
        if ($user->klassci_enseignant_id === null) {
            return $this->errorResponse(
                'Vous devez être un enseignant KLASSCI synchronisé pour créer une évaluation.',
                403,
            );
        }

        // Le service applique sa propre whitelist (FILLABLE_FROM_REQUEST) — on
        // passe `all()` pour préserver les champs hors validation rules
        // (status, is_online, allow_retake, etc.) que l'original lisait via
        // `$request->only([...])`.
        $result = $this->creationService->create($request->all(), $user);

        // Non migré vers successResponse()/errorResponse() : l'enveloppe est
        // déjà construite PAR LE SERVICE (`$result['payload']` = {success, …}
        // avec un statut dynamique 201/409/500). Le trait vit sur le controller,
        // pas sur le service ; la re-router via le trait imposerait de dépaqueter
        // puis brancher sur le contenu du résultat. Forwardé verbatim pour
        // préserver le JSON exact (axe #1 « DRY-only »). Idem update/destroy/publish.
        return response()->json($result['payload'], $result['status']);
    }

    public function update(UpdateEvaluationRequest $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);
        $user = $this->authenticatedUser($request);

        $result = $this->updateService->update($evaluation, $request->all(), $user);

        // Enveloppe construite par le service (cf. store) — forwardée verbatim.
        return response()->json($result['payload'], $result['status']);
    }

    public function destroy(DeleteEvaluationRequest $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);
        $user = $this->authenticatedUser($request);

        $result = $this->stateService->softDelete($evaluation, $user);

        // Enveloppe construite par le service (cf. store) — forwardée verbatim.
        return response()->json($result['payload'], $result['status']);
    }

    public function publish(PublishEvaluationRequest $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);
        $user = $this->authenticatedUser($request);

        $result = $this->stateService->publish($evaluation, $user);

        // Enveloppe construite par le service (cf. store) — forwardée verbatim.
        return response()->json($result['payload'], $result['status']);
    }
}
