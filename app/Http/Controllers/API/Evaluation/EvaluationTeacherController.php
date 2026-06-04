<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Evaluation;

use App\Http\Controllers\AuthenticatedController;
use App\Services\Evaluation\Teacher\TeacherEvaluationResultsService;
use App\Services\Evaluation\Teacher\TeacherEvaluationViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller for teacher-side evaluation views.
 *
 * Split §22 du god-controller (`EvaluationTeacherController` initial : 277
 * lignes / 3 endpoints) en 1 controller thin + 2 services SRP :
 *
 * - {@see TeacherEvaluationResultsService} — résultats agrégés par classe
 * - {@see TeacherEvaluationViewService}    — submissions list + preview
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * Les 2 services sont injectés par constructor, jamais via `app()`. Eux-mêmes
 * dépendent de PSR-3 `LoggerInterface` plutôt que de la Facade `Log::`.
 *
 * ## Endpoints (préservés à l'identique)
 *
 * - `GET /api/evaluations/{id}/preview`            → {@see preview}
 * - `GET /api/evaluations/{id}/submissions`        → {@see getSubmissions}
 * - `GET /api/evaluations/{id}/results-by-class`   → {@see getResultsByClass}
 *
 * @see app/Services/Evaluation/Teacher/TeacherEvaluationResultsService.php
 * @see app/Services/Evaluation/Teacher/TeacherEvaluationViewService.php
 */
final class EvaluationTeacherController extends AuthenticatedController
{
    public function __construct(
        private readonly TeacherEvaluationResultsService $resultsService,
        private readonly TeacherEvaluationViewService $viewService,
    ) {}

    public function getResultsByClass(Request $request, int $id): JsonResponse
    {
        $teacher = $this->authenticatedUser($request);

        $result = $this->resultsService->getResultsByClass($id, $teacher);

        return response()->json($result['payload'], $result['status']);
    }

    public function getSubmissions(Request $request, int $id): JsonResponse
    {
        $teacher = $this->authenticatedUser($request);

        $result = $this->viewService->getSubmissions($id, $teacher);

        return response()->json($result['payload'], $result['status']);
    }

    public function preview(Request $request, int $id): JsonResponse
    {
        $teacher = $this->authenticatedUser($request);

        $result = $this->viewService->preview($id, $teacher);

        return response()->json($result['payload'], $result['status']);
    }
}
