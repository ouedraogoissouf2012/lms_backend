<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Evaluation;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\DefinirLesTiragesRequest;
use App\Models\Evaluation;
use App\Services\Evaluation\Tirage\TiragesDUneEvaluation;
use Illuminate\Http\JsonResponse;

/**
 * #1702 (D3) : `GET|PUT /api/evaluations/{id}/tirages` — les règles de tirage au sort d'une
 * évaluation, avec, pour chacune, ce que sa catégorie offre et l'avertissement sous 2 × N.
 * L'accès est tranché par {@see DefinirLesTiragesRequest} ; les règles par
 * {@see TiragesDUneEvaluation}.
 */
final class EvaluationTiragesController extends AuthenticatedController
{
    public function __construct(private readonly TiragesDUneEvaluation $tirages) {}

    public function lire(DefinirLesTiragesRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->tirages->lire($this->evaluation($request, $id)));
    }

    public function definir(DefinirLesTiragesRequest $request, int $id): JsonResponse
    {
        try {
            return $this->successResponse($this->tirages->definir($this->evaluation($request, $id), $request->regles()));
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }

    /** L'évaluation de l'école de l'acteur (la requête a déjà établi qu'elle existe, là). */
    private function evaluation(DefinirLesTiragesRequest $request, int $id): Evaluation
    {
        return Evaluation::query()->withoutGlobalScope('institution')
            ->where('institution_id', $this->authenticatedUser($request)->institution_id)
            ->findOrFail($id);
    }
}
