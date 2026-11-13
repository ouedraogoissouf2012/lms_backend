<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Proxy;

use App\Http\Controllers\API\Proxy\Concerns\RendersKlassciProxyErrors;
use App\Http\Controllers\API\Proxy\Concerns\ResolvesPersonalKlassciToken;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Dashboard\TeacherDashboardReader;
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
            return $this->proxyErrorResponse($e);
        }
    }

    /**
     * GET /api/proxy/me/teacher-dashboard — Dashboard enseignant.
     *
     * Même route, autre source (R4, ADR-EI-01) : la charge KLASSCI relayée telle
     * quelle, ou la même forme lue en local dans une école en mode autonome. La
     * source est reçue par la MÉTHODE : Laravel garde le contrôleur sur l'objet
     * Route, et une source reçue à la construction resterait celle de la
     * première école servie.
     */
    public function teacherDashboard(Request $request, TeacherDashboardReader $tableau): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return $this->errorResponse('Utilisateur non authentifié', 401);
            }

            return response()->json($tableau->dashboardFor($user));
        } catch (\Exception $e) {
            return $this->proxyErrorResponse($e);
        }
    }
}
