<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Proxy;

use App\Http\Controllers\API\Proxy\Concerns\RendersKlassciProxyErrors;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Proxy KLASSCI — Endpoints académiques.
 *
 * Évaluations, emploi du temps et mutations enseignants (notes,
 * présences, statut cours).
 *
 * Extrait verbatim de ProxyController (395 lignes, 16 méthodes) splitté
 * en 3 controllers SRP par concern.
 *
 * @see PRODUCTION_STANDARDS.md §5 — Controllers ≤200 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict (constructor injection)
 */
final class ProxyAcademicController extends Controller
{
    use RendersKlassciProxyErrors;

    public function __construct(
        private readonly KlassciProxyService $klassciService,
    ) {
    }

    /**
     * GET /api/proxy/evaluations — Évaluations du porteur authentifié.
     */
    public function evaluations(Request $request): JsonResponse
    {
        try {
            $klassciToken = $this->personalKlassciToken($request);
            if ($klassciToken === null) {
                return $this->missingKlassciTokenResponse();
            }

            $filters = $request->only(['matiere_id', 'classe_id', 'statut']);
            $data = $this->klassciService->getEvaluations($klassciToken, $filters);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->proxyErrorResponse($e);
        }
    }

    /**
     * GET /api/proxy/emploi-temps — Emploi du temps du porteur authentifié.
     */
    public function emploiTemps(Request $request): JsonResponse
    {
        try {
            $klassciToken = $this->personalKlassciToken($request);
            if ($klassciToken === null) {
                return $this->missingKlassciTokenResponse();
            }

            $filters = $request->only(['classe_id', 'enseignant_id', 'date_debut', 'date_fin']);
            $data = $this->klassciService->getEmploiTemps($klassciToken, $filters);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->proxyErrorResponse($e);
        }
    }

    /**
     * POST /api/proxy/evaluations/{id}/notes — Sauvegarde des notes.
     */
    public function saveNotes(int $id, Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'notes' => 'required|array',
                'notes.*.etudiant_id' => 'required|integer',
                'notes.*.note' => 'nullable|numeric|min:0|max:20',
                'notes.*.is_absent' => 'boolean',
                'notes.*.commentaire' => 'nullable|string',
            ]);

            $data = $this->klassciService->saveNotes($id, $validated['notes']);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->proxyErrorResponse($e);
        }
    }

    /**
     * POST /api/proxy/cours/{id}/presences — Enregistre les présences.
     */
    public function savePresences(int $id, Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'date_cours' => 'required|date',
                'etudiants_presents' => 'required|array',
                'etudiants_absents' => 'nullable|array',
                'duree_effective_minutes' => 'nullable|integer',
                'commentaire' => 'nullable|string',
            ]);

            $presences = [
                'date_cours' => $validated['date_cours'],
                'etudiants_presents' => $validated['etudiants_presents'],
                'etudiants_absents' => $validated['etudiants_absents'] ?? [],
                'duree_effective_minutes' => $validated['duree_effective_minutes'] ?? null,
                'commentaire' => $validated['commentaire'] ?? null,
            ];

            $data = $this->klassciService->savePresences($id, $presences);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->proxyErrorResponse($e);
        }
    }

    /**
     * PUT /api/proxy/cours/{id}/statut — Met à jour le statut d'un cours.
     */
    public function updateCoursStatut(int $id, Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'statut' => 'required|string|in:en_cours,realise,annule',
                'commentaire' => 'nullable|string',
            ]);

            $data = $this->klassciService->updateCoursStatut(
                $id,
                $validated['statut'],
                $validated['commentaire'] ?? null,
            );

            return response()->json($data);
        } catch (\Exception $e) {
            return $this->proxyErrorResponse($e);
        }
    }

    /**
     * #591 — Jeton KLASSCI **personnel** de l'utilisateur de CETTE requête, ou
     * `null` s'il n'en a pas.
     *
     * Lu sur l'objet `Request` passé en argument, jamais sur un collaborateur
     * injecté au constructeur : Laravel mémoïse l'instance de contrôleur sur
     * l'objet `Route` (seul Octane appelle `Route::flushController()`), donc une
     * dépendance résolue au constructeur peut survivre à la requête et servir le
     * porteur du PREMIER appelant à tous les suivants. L'argument de méthode,
     * lui, est per-requête par construction.
     *
     * ⚠️ Appeler DANS le `try` de l'action : `klassci_token` est un accesseur sur
     * une colonne castée `encrypted` ({@see \App\Models\User}), donc la lecture
     * peut lever une `DecryptException` (ciphertext corrompu, rotation
     * d'`APP_KEY`). Hors du `try`, elle échapperait à
     * {@see RendersKlassciProxyErrors} et sortirait en 500 brut.
     */
    private function personalKlassciToken(Request $request): ?string
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return null;
        }

        $token = $user->klassci_token;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * #591 — fail-secure : une ressource liée à l'identité exige une identité.
     * Sans jeton personnel, on refuse plutôt que de retomber sur le jeton
     * d'institution/système — qui exposerait la vue tenant-wide à un compte qui
     * n'y a pas droit.
     *
     * Même **statut** que {@see ProxyDashboardController} (401) ; ce dernier
     * distingue en plus « utilisateur non authentifié » de « jeton absent », ce
     * qui est sans objet ici puisque `auth:sanctum` garantit l'utilisateur.
     */
    private function missingKlassciTokenResponse(): JsonResponse
    {
        return $this->errorResponse('Token KLASSCI non trouvé. Veuillez vous reconnecter.', 401);
    }
}
