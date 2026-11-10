<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Dashboard\TeacherStatsService;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TeacherStatsController — endpoint thin pour les compteurs de contenu
 * d'un enseignant (matières, classes, évaluations, leçons).
 *
 * Issue #364 : l'agrégation est extraite verbatim dans
 * {@see TeacherStatsService}. Restent ici les préoccupations HTTP :
 *
 *   - le garde de rôle 403 (défense en profondeur : la route porte déjà
 *     `role:enseignant,coordinateur`) ;
 *   - le catch 500 avec son enveloppe legacy à clé racine `error`
 *     (chaîne) que `errorResponse()` ne reproduit pas — conservé inline
 *     (axe #1 « DRY-only »).
 *
 * ## DI strict (§1.6 D)
 *
 * `TeacherStatsService` et le journal des exceptions attrapées
 * (`CaughtExceptionLog`) sont injectés par le
 * constructeur (la dépendance morte `KlassciProxyService`, jamais
 * utilisée par `getStats`, a été supprimée).
 *
 * @see app/Services/Dashboard/TeacherStatsService.php
 */
class TeacherStatsController extends Controller
{
    public function __construct(
        private readonly TeacherStatsService $statsService,
        private readonly CaughtExceptionLog $journal,
    ) {
    }

    /**
     * Récupérer les statistiques d'un enseignant.
     *
     * GET /api/teacher/stats
     */
    public function getStats(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // `instanceof` couvre le null ET type la variable pour le
            // service — même sémantique que l'ancien `!$user`.
            if (! $user instanceof User || ! $user->isStaff()) {
                return $this->errorResponse('Accès réservé aux enseignants', 403);
            }

            return $this->successResponse($this->statsService->buildStats($user));
        } catch (\Exception $e) {
            $this->journal->error('Erreur récupération statistiques enseignant:', $e);

            // Non migré vers errorResponse() : expose une clé racine `error`
            // (chaîne) que le trait ne reproduit pas — il n'émet que `errors`
            // (tableau structuré). Conservé inline (axe #1 « DRY-only »).
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }
}
