<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\GenerateActivityReportRequest;
use App\Http\Requests\GenerateAttendanceReportRequest;
use App\Http\Requests\GenerateGradesReportRequest;
use App\Services\Report\AsyncReportDispatcher;
use App\Services\Report\AsyncReportStore;
use App\Services\Report\ReportGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        private readonly AsyncReportDispatcher $asyncReports,
        private readonly AsyncReportStore $asyncReportStore,
    ) {
    }

    /**
     * POST /api/admin/reports/attendance — rapport de présences (PDF).
     */
    public function generateAttendanceReport(GenerateAttendanceReportRequest $request): Response|JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if (! $this->wantsSync($request)) {
            return $this->accepted($this->asyncReports->dispatch('attendance', $request->validated(), $user));
        }

        $result = $this->reports->generateAttendance($request->validated(), $user);

        return $this->toHttpResponse($result);
    }

    /**
     * POST /api/admin/reports/grades — rapport de notes (PDF).
     */
    public function generateGradesReport(GenerateGradesReportRequest $request): Response|JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if (! $this->wantsSync($request)) {
            return $this->accepted($this->asyncReports->dispatch('grades', $request->validated(), $user));
        }

        $result = $this->reports->generateGrades($request->validated(), $user);

        return $this->toHttpResponse($result);
    }

    /**
     * POST /api/admin/reports/activity — rapport d'activité système (PDF).
     */
    public function generateActivityReport(GenerateActivityReportRequest $request): Response|JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if (! $this->wantsSync($request)) {
            return $this->accepted($this->asyncReports->dispatch('activity', $request->validated(), $user));
        }

        $result = $this->reports->generateActivity($request->validated(), $user);

        return $this->toHttpResponse($result);
    }

    public function asyncStatus(Request $request, string $id): JsonResponse
    {
        $report = $this->ownedReport($request, $id);
        if ($report === null) {
            return response()->json(['success' => false, 'message' => 'Rapport introuvable'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->publicReport($report)]);
    }

    public function asyncDownload(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $report = $this->ownedReport($request, $id);
        if ($report === null) {
            return response()->json(['success' => false, 'message' => 'Rapport introuvable'], 404);
        }

        if (($report['status'] ?? null) !== 'completed' || ! isset($report['path'], $report['filename'])) {
            return response()->json(['success' => false, 'message' => 'Rapport non prêt'], 409);
        }

        $path = is_string($report['path']) ? $report['path'] : '';
        if (! Storage::disk('local')->exists($path)) {
            return response()->json(['success' => false, 'message' => 'Fichier introuvable'], 404);
        }

        $filename = is_string($report['filename']) ? $report['filename'] : 'rapport.pdf';

        return Storage::disk('local')->download($path, $filename);
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

    /**
     * #547 — La génération est ASYNCHRONE par défaut (le rendu DomPDF ne doit
     * jamais bloquer un worker FPM). Le mode synchrone (PDF binaire inline) est
     * un opt-out EXPLICITE réservé aux intégrations legacy : `?sync=1` ou
     * `Prefer: respond-sync`. Un opt-in async explicite (`?async=1` /
     * `Prefer: respond-async`) reste accepté pour rétro-compatibilité et force
     * l'async — donc si les deux drapeaux coexistent, le défaut sûr l'emporte.
     */
    private function wantsSync(Request $request): bool
    {
        if ($request->boolean('async') || $request->header('Prefer') === 'respond-async') {
            return false;
        }

        return $request->boolean('sync') || $request->header('Prefer') === 'respond-sync';
    }

    /**
     * @param array{id: string, status: string, status_url: string, download_url: string} $payload
     */
    private function accepted(array $payload): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $payload], 202);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ownedReport(Request $request, string $id): ?array
    {
        $user = $this->authenticatedUser($request);
        $report = $this->asyncReportStore->get($id);

        if ($report === null || ($report['user_id'] ?? null) !== $user->id) {
            return null;
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function publicReport(array $report): array
    {
        unset($report['path'], $report['user_id']);

        return $report;
    }
}
