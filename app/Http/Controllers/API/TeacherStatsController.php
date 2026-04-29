<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesApiExceptions;
use Illuminate\Http\Request;
use App\Models\Lesson;
use App\Models\Evaluation;
use App\Services\KlassciProxyService;

class TeacherStatsController extends Controller
{
    use HandlesApiExceptions;
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
        $user = $request->user();

        if (!$user || !in_array($user->role, ['enseignant', 'teacher', 'coordinateur', 'superAdmin'])) {
            $this->forbidden('Accès réservé aux enseignants', 'User with role ' . ($user->role ?? 'none') . ' attempted to access teacher stats');
        }

        $klassciId = $user->klassci_id;

        $lessonsCount = Lesson::where('enseignant_id', $klassciId)->count();

        $evaluationsCount = Evaluation::where('enseignant_id', $klassciId)->count();

        $matieresSet = [];
        $classesSet = [];

        $lessonMatieres = Lesson::where('enseignant_id', $klassciId)
            ->whereNotNull('matiere_id')
            ->distinct()
            ->pluck('matiere_id')
            ->toArray();

        foreach ($lessonMatieres as $matiereId) {
            $matieresSet[$matiereId] = true;
        }

        $evaluationMatieres = Evaluation::where('enseignant_id', $klassciId)
            ->whereNotNull('matiere_id')
            ->distinct()
            ->pluck('matiere_id')
            ->toArray();

        foreach ($evaluationMatieres as $matiereId) {
            $matieresSet[$matiereId] = true;
        }

        $lessonClasses = Lesson::where('enseignant_id', $klassciId)
            ->whereNotNull('classe_id')
            ->distinct()
            ->pluck('classe_id')
            ->toArray();

        foreach ($lessonClasses as $classeId) {
            $classesSet[$classeId] = true;
        }

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

        return response()->json([
            'success' => true,
            'data' => [
                'matieres' => $matieresCount,
                'classes' => $classesCount,
                'evaluations' => $evaluationsCount,
                'lessons' => $lessonsCount
            ]
        ]);
    }
}
