<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Proxy;

use App\Http\Controllers\API\Proxy\Concerns\RendersKlassciProxyErrors;
use App\Http\Controllers\API\Proxy\Concerns\ResolvesPersonalKlassciToken;
use App\Http\Controllers\Controller;
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
    use ResolvesPersonalKlassciToken;

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
        // Validation et resolution du jeton HORS du try : ni l'une ni l'autre n'est
        // un appel KLASSCI. Dans le try, la ValidationException etait avalee par le
        // catch(\Exception) et rendue 500 au lieu de 422 -- le client perdait les
        // erreurs par champ, et une simple faute de saisie ressemblait a une panne.
        $validated = $request->validate([
            'notes' => 'required|array',
            'notes.*.etudiant_id' => 'required|integer',
            'notes.*.note' => 'nullable|numeric|min:0|max:20',
            'notes.*.is_absent' => 'boolean',
            'notes.*.commentaire' => 'nullable|string',
        ]);

        $klassciToken = $this->personalKlassciToken($request);
        if ($klassciToken === null) {
            return $this->missingKlassciTokenResponse();
        }

        try {
            $data = $this->klassciService->saveNotes($klassciToken, $id, $validated['notes']);
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
        // Validation et resolution du jeton HORS du try : ni l'une ni l'autre n'est
        // un appel KLASSCI. Dans le try, la ValidationException etait avalee par le
        // catch(\Exception) et rendue 500 au lieu de 422 -- le client perdait les
        // erreurs par champ, et une simple faute de saisie ressemblait a une panne.
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

        $klassciToken = $this->personalKlassciToken($request);
        if ($klassciToken === null) {
            return $this->missingKlassciTokenResponse();
        }

        try {
            $data = $this->klassciService->savePresences($klassciToken, $id, $presences);
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
        // Validation et resolution du jeton HORS du try : ni l'une ni l'autre n'est
        // un appel KLASSCI. Dans le try, la ValidationException etait avalee par le
        // catch(\Exception) et rendue 500 au lieu de 422 -- le client perdait les
        // erreurs par champ, et une simple faute de saisie ressemblait a une panne.
        $validated = $request->validate([
            'statut' => 'required|string|in:en_cours,realise,annule',
            'commentaire' => 'nullable|string',
        ]);

        $klassciToken = $this->personalKlassciToken($request);
        if ($klassciToken === null) {
            return $this->missingKlassciTokenResponse();
        }

        try {
            $data = $this->klassciService->updateCoursStatut(
                $klassciToken,
                $id,
                $validated['statut'],
                $validated['commentaire'] ?? null,
            );

            return response()->json($data);
        } catch (\Exception $e) {
            return $this->proxyErrorResponse($e);
        }
    }

}
