<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Lesson;
use App\Models\Evaluation;
use App\Services\KlassciProxyService;
use Illuminate\Support\Facades\Log;

class TeacherStatsController extends Controller
{
    protected $klassciService;

    public function __construct(KlassciProxyService $klassciService)
    {
        $this->klassciService = $klassciService;
    }

    /**
     * Récupérer les statistiques d'un enseignant
     */
    public function getStats(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user || ! $user->isStaff()) {
                return $this->errorResponse('Accès réservé aux enseignants', 403);
            }

            // Récupérer le klassci_id de l'utilisateur
            $klassciId = $user->klassci_id;

            // 1. Compter les leçons créées par cet enseignant
            $lessonsCount = Lesson::where('enseignant_id', $klassciId)->count();

            // 2. Compter les évaluations créées par cet enseignant
            $evaluationsCount = Evaluation::where('enseignant_id', $klassciId)->count();

            // 3. Compter les matières et classes uniques depuis les leçons et évaluations
            $matieresSet = [];
            $classesSet = [];

            // Récupérer les matière_id uniques depuis les leçons
            $lessonMatieres = Lesson::where('enseignant_id', $klassciId)
                ->whereNotNull('matiere_id')
                ->distinct()
                ->pluck('matiere_id')
                ->toArray();

            foreach ($lessonMatieres as $matiereId) {
                $matieresSet[$matiereId] = true;
            }

            // Récupérer les matière_id uniques depuis les évaluations
            $evaluationMatieres = Evaluation::where('enseignant_id', $klassciId)
                ->whereNotNull('matiere_id')
                ->distinct()
                ->pluck('matiere_id')
                ->toArray();

            foreach ($evaluationMatieres as $matiereId) {
                $matieresSet[$matiereId] = true;
            }

            // Récupérer les classe_id uniques depuis les leçons
            $lessonClasses = Lesson::where('enseignant_id', $klassciId)
                ->whereNotNull('classe_id')
                ->distinct()
                ->pluck('classe_id')
                ->toArray();

            foreach ($lessonClasses as $classeId) {
                $classesSet[$classeId] = true;
            }

            // Récupérer les classe_id uniques depuis les évaluations
            $evaluationClasses = Evaluation::where('enseignant_id', $klassciId)
                ->whereNotNull('classe_id')
                ->distinct()
                ->pluck('classe_id')
                ->toArray();

            foreach ($evaluationClasses as $classeId) {
                $classesSet[$classeId] = true;
            }

            $matieresCount = count($matieresSet);
            $classesCount = count($classesSet);

            return $this->successResponse([
                'matieres' => $matieresCount,
                'classes' => $classesCount,
                'evaluations' => $evaluationsCount,
                'lessons' => $lessonsCount,
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération statistiques enseignant:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

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
