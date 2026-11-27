<?php

use App\Http\Controllers\API\Evaluation\BanqueDeQuestionsController;
use App\Http\Controllers\API\Evaluation\EvaluationCrudController;
// ============================================
// EVALUATIONS - Routes protégées
// ============================================
// Refactor #152/#153 — god-controller `EvaluationController` (1676 lignes)
// split en 4 controllers SRP sous `App\Http\Controllers\API\Evaluation\`.
use App\Http\Controllers\API\Evaluation\EvaluationKlassciSyncController;
use App\Http\Controllers\API\Evaluation\EvaluationTeacherController;
use App\Http\Controllers\API\Evaluation\Student\EvaluationStudentAttemptController;
use App\Http\Controllers\API\Evaluation\Student\EvaluationStudentListController;
use App\Http\Controllers\API\Evaluation\Student\EvaluationStudentSubmissionController;
use Illuminate\Support\Facades\Route;

// Routes accessibles à tous les utilisateurs authentifiés
Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function () {
    // Liste et consultation des évaluations
    Route::get('evaluations', [EvaluationCrudController::class, 'index']);

    // Évaluations de l'étudiant connecté (DOIT ÊTRE AVANT evaluations/{id})
    // Issue #123 : la route /evaluations/student/{klassciEtudiantId} a été
    // supprimée (vecteur d'IDOR — un étudiant pouvait forge l'ID d'un autre).
    // Le besoin legit "un étudiant voit ses propres évals" passe par cette
    // route sans param, dérivée du token Sanctum.
    Route::get('evaluations/student', [EvaluationStudentListController::class, 'myEvaluations']);

    // Récupérer une évaluation spécifique (APRÈS les routes spécifiques)
    Route::get('evaluations/{id}', [EvaluationCrudController::class, 'show']);

    // Démarrer et soumettre une évaluation
    Route::post('evaluations/{id}/start', [EvaluationStudentAttemptController::class, 'startEvaluation']);
    Route::post('evaluations/{id}/submit', [EvaluationStudentAttemptController::class, 'submitEvaluation']);

    // Récupérer la soumission de l'étudiant connecté
    Route::get('evaluations/{id}/my-submission', [EvaluationStudentSubmissionController::class, 'getMySubmission']);

    // État temporel en temps réel
    Route::get('evaluations/{id}/time-status', [EvaluationStudentAttemptController::class, 'getTimeStatus']);

    // Notes de l'étudiant groupées par matière
    Route::get('my-grades', [EvaluationStudentListController::class, 'myGrades']);
});

// Routes enseignants/coordinateurs uniquement
Route::middleware(['auth:sanctum', 'klassci.sync', 'personnel'])->group(function () {
    // CRUD des évaluations
    Route::post('evaluations', [EvaluationCrudController::class, 'store']);
    Route::put('evaluations/{id}', [EvaluationCrudController::class, 'update']);
    Route::delete('evaluations/{id}', [EvaluationCrudController::class, 'destroy']);

    // Publication
    Route::post('evaluations/{id}/publish', [EvaluationCrudController::class, 'publish']);

    // Prévisualisation enseignant (avant publication)
    Route::get('evaluations/{id}/preview', [EvaluationTeacherController::class, 'preview']);

    // Soumissions et résultats
    Route::get('evaluations/{id}/submissions', [EvaluationTeacherController::class, 'getSubmissions']);
    Route::post('evaluations/{id}/submissions/{submission}/grade', [EvaluationTeacherController::class, 'gradeSubmission']);

    // Synchronisation vers KLASSCI
    Route::post('evaluations/{id}/sync-klassci', [EvaluationKlassciSyncController::class, 'syncToKlassci']);

    // #1702 (D1) : la banque de questions (l'enseignant écrit, le coordinateur et l'admin lisent).
    Route::get('banque/categories', [BanqueDeQuestionsController::class, 'categories']);
    Route::post('banque/categories', [BanqueDeQuestionsController::class, 'creerCategorie']);
    Route::patch('banque/categories/{id}', [BanqueDeQuestionsController::class, 'renommerCategorie'])->whereNumber('id');
    Route::delete('banque/categories/{id}', [BanqueDeQuestionsController::class, 'supprimerCategorie'])->whereNumber('id');
    Route::get('banque/categories/{id}/questions', [BanqueDeQuestionsController::class, 'questions'])->whereNumber('id');
    Route::post('banque/categories/{id}/questions', [BanqueDeQuestionsController::class, 'ajouterQuestion'])->whereNumber('id');
    Route::put('banque/questions/{id}', [BanqueDeQuestionsController::class, 'modifierQuestion'])->whereNumber('id');
    Route::delete('banque/questions/{id}', [BanqueDeQuestionsController::class, 'supprimerQuestion'])->whereNumber('id');
});

// Routes du personnel (garde `personnel`) pour les résultats d'évaluations
Route::middleware(['auth:sanctum', 'klassci.sync', 'personnel'])->group(function () {
    // Résultats détaillés d'une évaluation avec tous les étudiants de la classe
    Route::get('evaluations/{id}/results-by-class', [EvaluationTeacherController::class, 'getResultsByClass']);
});
