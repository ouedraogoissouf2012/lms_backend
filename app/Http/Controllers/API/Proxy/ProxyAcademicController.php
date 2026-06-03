<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Proxy;

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
    public function __construct(
        private readonly KlassciProxyService $klassciService,
    ) {
    }

    /**
     * GET /api/proxy/evaluations — Évaluations.
     */
    public function evaluations(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['matiere_id', 'classe_id', 'statut']);
            $data = $this->klassciService->getEvaluations($filters);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse('Service indisponible. Veuillez réessayer.');
        }
    }

    /**
     * GET /api/proxy/emploi-temps — Emploi du temps.
     */
    public function emploiTemps(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['classe_id', 'enseignant_id', 'date_debut', 'date_fin']);
            $data = $this->klassciService->getEmploiTemps($filters);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse('Service indisponible. Veuillez réessayer.');
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
            return $this->errorResponse('Service indisponible. Veuillez réessayer.');
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
            return $this->errorResponse('Service indisponible. Veuillez réessayer.');
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
            return $this->errorResponse('Service indisponible. Veuillez réessayer.');
        }
    }

    /**
     * Réponse d'erreur standardisée.
     */
    private function errorResponse(string $message, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
