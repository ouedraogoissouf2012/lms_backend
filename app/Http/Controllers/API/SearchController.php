<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\GlobalSearchRequest;
use App\Services\Search\GlobalSearchService;
use App\Services\Search\SearchHistoryService;
use App\Services\Search\SearchSuggestionsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints de recherche (split-19/search).
 *
 * Controller thin (§5 ≤200 l) après extraction des trois services SRP :
 *
 *   - {@see GlobalSearchService}       — recherche cross-resources (lessons,
 *                                        évaluations, utilisateurs, classes &
 *                                        matières KLASSCI) + cache 5 min.
 *   - {@see SearchSuggestionsService}  — autocomplete trois sources.
 *   - {@see SearchHistoryService}      — historique per-user (10 entrées,
 *                                        30 jours de TTL).
 *
 * ## DI strict (§1.6 D)
 *
 * Les services sont injectés via le constructeur — aucune Facade ni `app()`.
 *
 * ## Comportement
 *
 * Aucun changement runtime : signatures des réponses JSON et clés de cache
 * identiques au monolithe pré-split.
 */
final class SearchController extends AuthenticatedController
{
    public function __construct(
        private readonly GlobalSearchService $globalSearch,
        private readonly SearchSuggestionsService $suggestions,
        private readonly SearchHistoryService $history,
    ) {
    }

    /**
     * Recherche globale dans le système.
     *
     * Sources couvertes : utilisateurs (admin/coordinateur seulement),
     * leçons, évaluations, classes & matières KLASSCI.
     */
    public function globalSearch(GlobalSearchRequest $request): JsonResponse
    {
        /** @var string $query */
        $query = $request->validated('query');
        $user = $this->authenticatedUser($request);
        $limit = (int) $request->validated('limit', 5);

        $payload = $this->globalSearch->search($query, $user, $limit);

        // Non migré vers successResponse() : expose des clés racine hors enveloppe
        // (`query`, `results`, `total`, `categories`, `sources_failed`) que le trait
        // ne reproduit pas. Les déplacer changerait le contrat client → conservé tel
        // quel (axe #1 « DRY-only », cf. LessonCrudController::myCourses).
        //
        // `sources_failed` (#505) est TOUJOURS présent, vide quand tout va bien : un
        // client n'a ainsi pas à distinguer « clé absente » de « aucune panne ».
        return response()->json([
            'success' => true,
            'query' => $query,
            'results' => $payload['results'],
            'total' => $payload['total'],
            'categories' => $payload['categories'],
            'sources_failed' => $payload['sources_failed'],
        ]);
    }

    /**
     * Suggestions de recherche (autocomplete).
     */
    public function suggestions(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:2|max:50',
        ]);

        /** @var string $query */
        $query = $request->input('query');
        $user = $this->authenticatedUser($request);

        // Non migré : clé racine `suggestions` hors enveloppe (cf. globalSearch).
        return response()->json([
            'success' => true,
            'suggestions' => $this->suggestions->getSuggestions($query, $user),
        ]);
    }

    /**
     * Historique de recherche de l'utilisateur.
     */
    public function searchHistory(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        // Non migré : clé racine `history` hors enveloppe (cf. globalSearch).
        return response()->json([
            'success' => true,
            'history' => $this->history->getHistory($user),
        ]);
    }

    /**
     * Sauvegarder une recherche dans l'historique.
     */
    public function saveSearchHistory(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|max:100',
        ]);

        $user = $this->authenticatedUser($request);
        /** @var string $query */
        $query = $request->input('query');

        $this->history->save($user, $query);

        return $this->successResponse(null, 'Recherche sauvegardée');
    }
}
