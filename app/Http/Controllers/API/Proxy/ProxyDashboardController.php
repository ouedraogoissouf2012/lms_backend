<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Proxy;

use App\Exceptions\BusinessException;
use App\Http\Controllers\API\Proxy\Concerns\RendersKlassciProxyErrors;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Dashboard\StudentDashboardReader;
use App\Services\Dashboard\TeacherDashboardReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Proxy KLASSCI — Dashboards utilisateur.
 *
 * Dashboards étudiant et enseignant : même route, autre source (ADR-EI-01).
 * Chaque tableau est un port reçu par la MÉTHODE ; la source KLASSCI relaie la
 * charge de KLASSCI avec le jeton du compte, la source locale la lit en local.
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

    /**
     * GET /api/proxy/me/dashboard — Dashboard étudiant.
     *
     * Retourne : classe, cours (matières), quiz (évaluations), notes,
     * statistiques. Même route, autre source, comme le tableau de l'enseignant :
     * la source est reçue par la MÉTHODE. Un refus métier de la source locale
     * (D57, inscription inactive) sort avec son statut et son motif.
     */
    public function studentDashboard(Request $request, StudentDashboardReader $tableau): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return $this->errorResponse('Utilisateur non authentifié', 401);
            }

            return response()->json($tableau->dashboardFor($user));
        } catch (BusinessException $e) {
            // Avant le rendu du proxy : une RuntimeException 409 y passerait pour un
            // refus relayé de KLASSCI, sans son message ni son motif.
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
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
