<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Proxy;

use App\Http\Controllers\API\Proxy\Concerns\RendersKlassciProxyErrors;
use App\Http\Controllers\API\Proxy\Concerns\ResolvesPersonalKlassciToken;
use App\Http\Controllers\Controller;
use App\Services\KlassciProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Proxy KLASSCI — Dashboards utilisateur.
 *
 * Dashboards étudiant et enseignant : récupère le token KLASSCI stocké
 * en BDD pour l'utilisateur Sanctum authentifié et proxifie la requête.
 *
 * Extrait verbatim de ProxyController (395 lignes, 16 méthodes) splitté
 * en 3 controllers SRP par concern.
 *
 * @see PRODUCTION_STANDARDS.md §5 — Controllers ≤200 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict (constructor injection)
 */
final class ProxyDashboardController extends Controller
{
    use RendersKlassciProxyErrors;
    use ResolvesPersonalKlassciToken;

    public function __construct(
        private readonly KlassciProxyService $klassciService,
    ) {
    }

    /**
     * GET /api/proxy/me/dashboard — Dashboard étudiant.
     *
     * Retourne : classe, cours (matières), quiz (évaluations), notes,
     * statistiques.
     */
    public function studentDashboard(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if ($user === null) {
                return $this->errorResponse('Utilisateur non authentifié', 401);
            }

            $klassciToken = $this->personalKlassciToken($request);
            if ($klassciToken === null) {
                return $this->missingKlassciTokenResponse();
            }

            Log::info('Student Dashboard request', [
                'user_id' => $user->id,
                'klassci_id' => $user->klassci_id,
                'has_klassci_token' => ! empty($klassciToken),
            ]);

            $data = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'me/dashboard',
                'GET',
            );

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Student Dashboard error', ['error' => $e->getMessage()]);
            return $this->proxyErrorResponse($e);
        }
    }

    /**
     * GET /api/proxy/me/teacher-dashboard — Dashboard enseignant.
     *
     * Retourne : matieres, classes, evaluations, seances, statistiques.
     */
    public function teacherDashboard(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if ($user === null) {
                return $this->errorResponse('Utilisateur non authentifié', 401);
            }

            $klassciToken = $this->personalKlassciToken($request);
            if ($klassciToken === null) {
                return $this->missingKlassciTokenResponse();
            }

            Log::info('Teacher Dashboard request', [
                'user_id' => $user->id,
                'klassci_id' => $user->klassci_id,
                'has_klassci_token' => ! empty($klassciToken),
            ]);

            $data = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'me/teacher-dashboard',
                'GET',
            );

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Teacher Dashboard error', ['error' => $e->getMessage()]);
            return $this->proxyErrorResponse($e);
        }
    }
}
