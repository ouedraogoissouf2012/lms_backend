<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\ProxyController;
use App\Http\Controllers\API\AuthController;

/*
|--------------------------------------------------------------------------
| API Routes - LMS KLASSCI Backend
|--------------------------------------------------------------------------
|
| Routes API pour le backend LMS
| Toutes les routes sont préfixées par /api automatiquement
|
*/

// Route de test (publique)
Route::get('/ping', function () {
    return response()->json([
        'success' => true,
        'message' => 'LMS KLASSCI Backend API is running!',
        'timestamp' => now()->toIso8601String(),
        'version' => '1.0.0',
    ]);
});

// ============================================
// AUTHENTIFICATION - Routes publiques
// ============================================
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']); // À implémenter si besoin
});

// ============================================
// AUTHENTIFICATION - Routes protégées
// ============================================
Route::prefix('auth')->middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::get('/check', [AuthController::class, 'check']);
});

// ============================================
// PROXY KLASSCI - Routes publiques
// ============================================
Route::prefix('proxy')->group(function () {
    // Test de connexion (public)
    Route::get('/test-connection', [ProxyController::class, 'testConnection']);
});

// ============================================
// PROXY KLASSCI - Routes protégées (Tous les rôles authentifiés)
// ============================================
Route::prefix('proxy')
    ->middleware(['auth:sanctum', 'klassci.sync'])
    ->group(function () {

    // Structure organisationnelle (Accessible à tous)
    Route::get('/structure', [ProxyController::class, 'structure']);
    Route::get('/filieres', [ProxyController::class, 'filieres']);
    Route::get('/niveaux-etudes', [ProxyController::class, 'niveauxEtudes']);

    // Classes et étudiants (Accessible à tous)
    Route::get('/classes', [ProxyController::class, 'classes']);
    Route::get('/classes/{id}/etudiants', [ProxyController::class, 'etudiants']);

    // Matières et enseignants (Accessible à tous)
    Route::get('/matieres', [ProxyController::class, 'matieres']);
    Route::get('/enseignants', [ProxyController::class, 'enseignants']);

    // Emploi du temps (Accessible à tous)
    Route::get('/emploi-temps', [ProxyController::class, 'emploiTemps']);

    // Évaluations - Lecture (Accessible à tous)
    Route::get('/evaluations', [ProxyController::class, 'evaluations']);
});

// ============================================
// PROXY KLASSCI - Routes ENSEIGNANTS uniquement
// ============================================
Route::prefix('proxy')
    ->middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur'])
    ->group(function () {

    // Sauvegarder les notes (Enseignants/Coordinateurs uniquement)
    Route::post('/evaluations/{id}/notes', [ProxyController::class, 'saveNotes']);

    // Sauvegarder les présences (Enseignants/Coordinateurs uniquement)
    Route::post('/cours/{id}/presences', [ProxyController::class, 'savePresences']);

    // Mettre à jour statut cours (Enseignants/Coordinateurs uniquement)
    Route::put('/cours/{id}/statut', [ProxyController::class, 'updateCoursStatut']);
});

// ============================================
// ROUTE USER PROFILE (Protégée)
// ============================================
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return response()->json([
        'success' => true,
        'data' => $request->user(),
    ]);
});

// ============================================
// LESSONS (Cours/Leçons) - Routes protégées
// ============================================
use App\Http\Controllers\API\LessonController;

// Routes accessibles à tous les utilisateurs authentifiés
Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function () {
    // Liste et consultation des cours
    Route::get('lessons', [LessonController::class, 'index']);
    Route::get('lessons/{id}', [LessonController::class, 'show']);

    // Progression (Tous peuvent voir leur progression)
    Route::get('lessons/{id}/progress', [LessonController::class, 'getProgress']);
    Route::post('lessons/{id}/progress', [LessonController::class, 'updateProgress']);
    Route::post('lessons/{id}/complete', [LessonController::class, 'markComplete']);
    Route::post('lessons/{id}/rating', [LessonController::class, 'rate']);
});

// Routes enseignants/coordinateurs uniquement
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur'])->group(function () {
    // CRUD des cours
    Route::post('lessons', [LessonController::class, 'store']);
    Route::put('lessons/{id}', [LessonController::class, 'update']);
    Route::delete('lessons/{id}', [LessonController::class, 'destroy']);

    // Actions spéciales
    Route::post('lessons/{id}/publish', [LessonController::class, 'publish']);
    Route::post('lessons/{id}/unpublish', [LessonController::class, 'unpublish']);
});

// ============================================
// FORUM - Routes protégées
// ============================================
use App\Http\Controllers\API\ForumController;

// Routes accessibles à tous les utilisateurs authentifiés
Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('forum')->group(function () {
    // Topics
    Route::get('topics', [ForumController::class, 'index']);
    Route::post('topics', [ForumController::class, 'store']);
    Route::get('topics/{id}', [ForumController::class, 'show']);
    Route::put('topics/{id}', [ForumController::class, 'update']);
    Route::delete('topics/{id}', [ForumController::class, 'destroy']);

    // Posts
    Route::post('topics/{id}/posts', [ForumController::class, 'storePost']);
    Route::put('posts/{id}', [ForumController::class, 'updatePost']);
    Route::delete('posts/{id}', [ForumController::class, 'destroyPost']);

    // Marquer solution (Enseignants/Auteur topic)
    Route::post('posts/{id}/solution', [ForumController::class, 'markAsSolution']);
});

// Routes enseignants/coordinateurs/admin uniquement
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur'])->prefix('forum')->group(function () {
    Route::post('topics/{id}/close', [ForumController::class, 'closeTopic']);
    Route::post('topics/{id}/pin', [ForumController::class, 'pinTopic']);
});

// ============================================
// FILES - Routes protégées
// ============================================
use App\Http\Controllers\API\FileController;

// Routes accessibles à tous les utilisateurs authentifiés
Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function () {
    // Liste et consultation des fichiers
    Route::get('files', [FileController::class, 'index']);
    Route::get('files/{id}', [FileController::class, 'show']);
    Route::get('files/{id}/download', [FileController::class, 'download'])->name('api.files.download');

    // Upload de fichiers (Tous peuvent uploader)
    Route::post('files/upload', [FileController::class, 'upload']);

    // Statistiques
    Route::get('files/stats', [FileController::class, 'stats']);

    // Mise à jour et suppression (propriétaire ou admin)
    Route::put('files/{id}', [FileController::class, 'update']);
    Route::delete('files/{id}', [FileController::class, 'destroy']);
});

// ============================================
// QUIZZES - Routes protégées
// ============================================
use App\Http\Controllers\API\QuizController;

// Routes accessibles à tous les utilisateurs authentifiés
Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function () {
    // Liste et consultation des quiz
    Route::get('quizzes', [QuizController::class, 'index']);
    Route::get('quizzes/{id}', [QuizController::class, 'show']);

    // Démarrer et soumettre une tentative
    Route::post('quizzes/{id}/start', [QuizController::class, 'startAttempt']);
    Route::post('quiz-attempts/{id}/submit', [QuizController::class, 'submitAttempt']);

    // NOUVEAU: Timer et sauvegarde de progression
    Route::get('quiz-attempts/{id}/time-remaining', [QuizController::class, 'checkTimeRemaining']);
    Route::post('quiz-attempts/{id}/save-progress', [QuizController::class, 'saveProgress']);

    // Consulter une tentative
    Route::get('quiz-attempts/{id}', [QuizController::class, 'showAttempt']);
});

// Routes enseignants/coordinateurs uniquement
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur'])->group(function () {
    // CRUD des quiz
    Route::post('quizzes', [QuizController::class, 'store']);
    Route::put('quizzes/{id}', [QuizController::class, 'update']);
    Route::delete('quizzes/{id}', [QuizController::class, 'destroy']);

    // Publication
    Route::post('quizzes/{id}/publish', [QuizController::class, 'publish']);

    // Gestion des tentatives
    Route::get('quizzes/{id}/attempts', [QuizController::class, 'getAttempts']);
    Route::post('quiz-attempts/{id}/grade', [QuizController::class, 'gradeAttempt']);
});

// ============================================
// NOTIFICATIONS - Routes protégées
// ============================================
use App\Http\Controllers\API\NotificationController;

Route::middleware(['auth:sanctum'])->prefix('notifications')->group(function () {
    // Liste et compteurs
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('/unread-count', [NotificationController::class, 'unreadCount']);

    // Actions globales (AVANT les routes avec {id})
    Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/read', [NotificationController::class, 'destroyAllRead']);

    // Actions sur une notification (APRÈS les routes spécifiques)
    Route::get('/{id}', [NotificationController::class, 'show']);
    Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/{id}/unread', [NotificationController::class, 'markAsUnread']);
    Route::delete('/{id}', [NotificationController::class, 'destroy']);
});

// ============================================
// DASHBOARD - Routes protégées
// ============================================
use App\Http\Controllers\API\DashboardController;

Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('dashboard')->group(function () {
    // Dashboard étudiant (tous les utilisateurs authentifiés)
    Route::get('/student', [DashboardController::class, 'student']);

    // Dashboard enseignant (enseignants et coordinateurs uniquement)
    Route::get('/teacher', [DashboardController::class, 'teacher'])
        ->middleware('role:enseignant,coordinateur');

    // Statistiques globales (coordinateurs et admin uniquement)
    Route::get('/stats', [DashboardController::class, 'stats'])
        ->middleware('role:coordinateur,admin');
});
