<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\GenerateActivityReportRequest;
use App\Http\Requests\GenerateAttendanceReportRequest;
use App\Http\Requests\GenerateGradesReportRequest;
use App\Services\Report\ReportGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Thin controller — génération de rapports PDF (présences / notes / activité).
 *
 * Split §5 (≤200 l) : la totalité de l'orchestration métier + agrégation +
 * rendu Blade → DomPDF a été extraite dans
 * {@see ReportGenerationService}. Le controller se limite à :
 *
 *   1. récupérer les payloads validés via les `FormRequest`s dédiés
 *      (l'autorisation coordinateur/admin reste appliquée par eux) ;
 *   2. déléguer au service ;
 *   3. transformer le `['status', 'payload']` retourné en réponse HTTP
 *      finale — `Response` (PDF binaire) pour `status === 200`,
 *      `JsonResponse` pour les erreurs.
 *
 * §1.6 D : service injecté via le constructor, jamais `app()` ni Facade.
 */
final class ReportController extends AuthenticatedController
{
    public function __construct(
        private readonly ReportGenerationService $reports,
    ) {
    }

    /**
     * POST /api/admin/reports/attendance — rapport de présences (PDF).
     */
    public function generateAttendanceReport(GenerateAttendanceReportRequest $request): Response|JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->reports->generateAttendance($request->validated(), $user);

        return $this->toHttpResponse($result);
    }

    /**
     * POST /api/admin/reports/grades — rapport de notes (PDF).
     */
    public function generateGradesReport(GenerateGradesReportRequest $request): Response|JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->reports->generateGrades($request->validated(), $user);

        return $this->toHttpResponse($result);
    }

    /**
     * POST /api/admin/reports/activity — rapport d'activité système (PDF).
     */
    public function generateActivityReport(GenerateActivityReportRequest $request): Response|JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->reports->generateActivity($request->validated(), $user);

        return $this->toHttpResponse($result);
    }

    /**
     * Transforme le contrat de retour du service en réponse HTTP :
     *   - `status === 200` → `payload` est déjà la `Response` PDF construite
     *     par `Pdf::download()` (binaire + Content-Disposition attachment).
     *   - sinon → `payload` est un tableau JSON-sérialisable enveloppé dans
     *     une `JsonResponse` avec le code HTTP du service.
     *
     * @param array{status: int, payload: Response|array<string, mixed>} $result
     */
    private function toHttpResponse(array $result): Response|JsonResponse
    {
        $payload = $result['payload'];

        if ($result['status'] === 200 && $payload instanceof Response) {
            return $payload;
        }

        /** @var array<string, mixed> $jsonPayload */
        $jsonPayload = is_array($payload) ? $payload : ['success' => false];

        // Non migré vers successResponse()/errorResponse() : `$jsonPayload` est le
        // payload BRUT du service (clés racine décidées par ReportGenerationService),
        // et le repli `['success' => false]` n'a pas de `message`. Aucune enveloppe
        // `{...success...}` standardisable ici — RACINE laissée inline (axe #1,
        // règle MIXTE).
        return response()->json($jsonPayload, $result['status']);
    }
}
