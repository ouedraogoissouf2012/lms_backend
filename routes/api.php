<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\ProxyController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\AdminAnalyticsController;
use App\Http\Controllers\API\ReportController;
use App\Http\Controllers\API\NotificationsController;
use App\Http\Controllers\API\SearchController;
use App\Http\Controllers\API\TeacherStatsController;
use App\Http\Controllers\API\InstitutionController;

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

// Liste des institutions actives (public, pour sélecteur sur page login)
Route::get('/institutions/active', function () {
    $institutions = \App\Models\Institution::where('is_active', true)
        ->orderBy('name')
        ->get(['slug', 'name', 'logo_url', 'primary_color']);

    return response()->json([
        'success' => true,
        'data' => $institutions,
    ]);
});

// ============================================
// INSTITUTION - Informations sur l'institution courante
// ============================================
Route::get('/institution/current', function () {
    $tenantManager = app(\App\Services\TenantManager::class);
    $institution = $tenantManager->get();

    if (!$institution) {
        return response()->json([
            'success' => false,
            'message' => 'Aucune institution résolue',
        ], 400);
    }

    return response()->json([
        'success' => true,
        'data' => [
            'slug' => $institution->slug,
            'name' => $institution->name,
            'logo_url' => $institution->logo_url,
            'primary_color' => $institution->primary_color,
        ],
    ]);
});


// ============================================
// AUTHENTIFICATION - Routes publiques
// ============================================
Route::prefix('auth')->group(function () {
    // Login: 60 requests per minute (1/sec) - allows retry on slow connections
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:60,1');
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
// PROXY KLASSCI - Test de connexion (SÉCURISÉ)
// ============================================
Route::prefix('proxy')
    ->middleware(['auth:sanctum', 'klassci.sync', 'role:coordinateur,superAdmin,supradmin'])
    ->group(function () {
        // Test de connexion KLASSCI — réservé aux admins
        Route::get('/test-connection', [ProxyController::class, 'testConnection']);
    });

// ============================================
// PROXY KLASSCI - Données organisationnelles (SÉCURISÉ)
// Routes authentifiées pour tous les rôles
// ============================================
Route::prefix('proxy')
    ->middleware(['auth:sanctum', 'klassci.sync'])
    ->group(function () {
        // Structure organisationnelle
        Route::get('/structure', [ProxyController::class, 'structure']);
        Route::get('/filieres', [ProxyController::class, 'filieres']);
        Route::get('/niveaux-etudes', [ProxyController::class, 'niveauxEtudes']);

        // Classes et étudiants
        Route::get('/classes', [ProxyController::class, 'classes']);
        Route::get('/classes/{id}/etudiants', [ProxyController::class, 'etudiants']);

        // Matières et enseignants
        Route::get('/matieres', [ProxyController::class, 'matieres']);
        Route::get('/matieres/{id}', [ProxyController::class, 'matiereDetails']);
        Route::get('/enseignants', [ProxyController::class, 'enseignants']);

        // Emploi du temps
        Route::get('/emploi-temps', [ProxyController::class, 'emploiTemps']);

        // Évaluations - Lecture
        Route::get('/evaluations', [ProxyController::class, 'evaluations']);
    });

// ============================================
// PROXY KLASSCI - Routes ENSEIGNANTS uniquement
// ============================================
Route::prefix('proxy')
    ->middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur'])
    ->group(function () {

    // Sauvegarder les notes (Enseignants/Coordinateurs uniquement) - Rate limited: 60/min
    Route::post('/evaluations/{id}/notes', [ProxyController::class, 'saveNotes'])
        ->middleware('throttle:60,1');

    // Sauvegarder les présences (Enseignants/Coordinateurs uniquement) - Rate limited: 60/min
    Route::post('/cours/{id}/presences', [ProxyController::class, 'savePresences'])
        ->middleware('throttle:60,1');

    // Mettre à jour statut cours (Enseignants/Coordinateurs uniquement) - Rate limited: 30/min
    Route::put('/cours/{id}/statut', [ProxyController::class, 'updateCoursStatut'])
        ->middleware('throttle:30,1');
});

// ============================================
// PROXY KLASSCI - Routes utilisateur (authentifié via Sanctum)
// Ces routes récupèrent le token KLASSCI depuis la base de données
// ============================================
Route::prefix('proxy')
    ->middleware(['auth:sanctum', 'klassci.sync'])
    ->group(function () {
        // Dashboard étudiant (récupère le token KLASSCI de l'utilisateur)
        Route::get('/me/dashboard', [ProxyController::class, 'studentDashboard']);

        // Dashboard enseignant (réservé aux enseignants/coordinateurs)
        Route::get('/me/teacher-dashboard', [ProxyController::class, 'teacherDashboard'])
            ->middleware('role:enseignant,coordinateur');
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
// CHAPTERS (Chapitres de leçons) - Routes protégées
// NOUVELLE STRUCTURE: Chapter belongsTo Lesson
// ============================================
use App\Http\Controllers\API\ChapterController;

// Routes accessibles à tous les utilisateurs authentifiés
Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function () {
    // Liste chapitres d'une leçon
    Route::get('lessons/{lessonId}/chapters', [ChapterController::class, 'index']);
    // Détails d'un chapitre
    Route::get('chapters/{id}', [ChapterController::class, 'show']);

    // Progression des chapitres
    Route::get('lessons/{lessonId}/chapter-progress', [\App\Http\Controllers\API\ChapterProgressController::class, 'getLessonProgress']);
    Route::get('chapters/{chapterId}/progress', [\App\Http\Controllers\API\ChapterProgressController::class, 'getChapterProgress']);
    Route::post('chapters/{chapterId}/complete', [\App\Http\Controllers\API\ChapterProgressController::class, 'markAsCompleted']);
    Route::post('chapters/{chapterId}/time', [\App\Http\Controllers\API\ChapterProgressController::class, 'updateTimeSpent']);
});

// Routes enseignants/coordinateurs/admins
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur,admin'])->group(function () {
    // CRUD des chapitres
    Route::post('lessons/{lessonId}/chapters', [ChapterController::class, 'store'])
        ->middleware('throttle:60,1');
    Route::match(['put', 'patch'], 'chapters/{id}', [ChapterController::class, 'update'])
        ->middleware('throttle:30,1');
    Route::delete('chapters/{id}', [ChapterController::class, 'destroy'])
        ->middleware('throttle:30,1');

    // Upload fichier PowerPoint/Word/PDF (max 30 MB) - Rate limited: 60/min
    Route::post('chapters/{chapterId}/upload', [ChapterController::class, 'uploadFile'])
        ->middleware('throttle:60,1');

    // Réorganisation (drag & drop) - Rate limited: 100/min (frequent user action)
    Route::post('lessons/{lessonId}/chapters/reorder', [ChapterController::class, 'reorder'])
        ->middleware('throttle:100,1');
});

// ============================================
// KNOWLEDGE CHECKS (Quiz "Testez vos connaissances")
// ============================================
use App\Http\Controllers\API\KnowledgeCheckController;

Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function () {
    // Liste des quiz d'un chapitre
    Route::get('knowledge-checks', [KnowledgeCheckController::class, 'index']);
    // Quiz par chapitre (doit être AVANT {id} pour éviter le conflit)
    Route::get('knowledge-checks/chapter/{chapterId}', [KnowledgeCheckController::class, 'getByChapter']);
    Route::get('knowledge-checks/{id}', [KnowledgeCheckController::class, 'show']);

    // Tentatives (étudiants)
    Route::post('knowledge-checks/{id}/start', [KnowledgeCheckController::class, 'startAttempt'])
        ->middleware('throttle:300,1');
    Route::post('knowledge-checks/{id}/submit', [KnowledgeCheckController::class, 'submitAttempt'])
        ->middleware('throttle:60,1');
    Route::get('knowledge-checks/{id}/my-attempts', [KnowledgeCheckController::class, 'myAttempts']);

    // CRUD (enseignants/admins)
    Route::post('knowledge-checks', [KnowledgeCheckController::class, 'store']);
    Route::put('knowledge-checks/{id}', [KnowledgeCheckController::class, 'update']);
    Route::delete('knowledge-checks/{id}', [KnowledgeCheckController::class, 'destroy']);
});

// ============================================
// LESSONS (Cours/Leçons) - Routes protégées
// ============================================
use App\Http\Controllers\API\LessonController;

// Routes accessibles à tous les utilisateurs authentifiés
Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function () {
    // Liste et consultation des cours
    Route::get('lessons', [LessonController::class, 'index']);
    Route::get('lessons/my-courses', [LessonController::class, 'myCourses']); // Cours de l'étudiant avec filtres
    Route::get('lessons/{id}', [LessonController::class, 'show']);

    // Progression (Tous peuvent voir leur progression)
    Route::get('lessons/{id}/progress', [LessonController::class, 'getProgress']);
    Route::post('lessons/{id}/progress', [LessonController::class, 'updateProgress'])
        ->middleware('throttle:300,1');
    Route::post('lessons/{id}/complete', [LessonController::class, 'markComplete'])
        ->middleware('throttle:300,1');
    Route::post('lessons/{id}/rating', [LessonController::class, 'rate'])
        ->middleware('throttle:300,1');
});

// Routes enseignants/coordinateurs/admins
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur,admin'])->group(function () {
    // CRUD des cours
    Route::post('lessons', [LessonController::class, 'store'])
        ->middleware('throttle:60,1');
    Route::match(['put', 'patch'], 'lessons/{id}', [LessonController::class, 'update'])
        ->middleware('throttle:30,1');
    Route::delete('lessons/{id}', [LessonController::class, 'destroy'])
        ->middleware('throttle:30,1');

    // Actions spéciales
    Route::post('lessons/{id}/publish', [LessonController::class, 'publish'])
        ->middleware('throttle:100,1');
    Route::post('lessons/{id}/unpublish', [LessonController::class, 'unpublish'])
        ->middleware('throttle:100,1');
});

// ============================================
// FORUM - Routes protégées
// ============================================
use App\Http\Controllers\API\ForumController;

// Routes accessibles à tous les utilisateurs authentifiés
Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('forum')->group(function () {
    // Topics
    Route::get('topics', [ForumController::class, 'index']);
    Route::post('topics', [ForumController::class, 'store'])
        ->middleware('throttle:100,1');
    Route::get('topics/{topic}', [ForumController::class, 'show']);
    Route::put('topics/{topic}', [ForumController::class, 'update'])
        ->middleware('throttle:100,1');
    Route::delete('topics/{topic}', [ForumController::class, 'destroy'])
        ->middleware('throttle:30,1');

    // Posts
    Route::post('topics/{topic}/posts', [ForumController::class, 'storePost'])
        ->middleware('throttle:100,1');
    Route::put('posts/{post}', [ForumController::class, 'updatePost'])
        ->middleware('throttle:100,1');
    Route::delete('posts/{post}', [ForumController::class, 'destroyPost'])
        ->middleware('throttle:30,1');

    // Marquer solution
    Route::post('posts/{post}/solution', [ForumController::class, 'markAsSolution'])
        ->middleware('throttle:100,1');
});

// Routes pour clôturer et épingler des topics (formateurs/coordinateurs/admins)
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur,admin'])->prefix('forum')->group(function () {
    Route::post('topics/{topic}/close', [ForumController::class, 'closeTopic']);
    Route::post('topics/{topic}/pin', [ForumController::class, 'pinTopic']);
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

    // Upload de fichiers (Tous peuvent uploader) - Rate limited: 60/min (with size limit)
    Route::post('files/upload', [FileController::class, 'upload'])
        ->middleware('throttle:60,1');

    // Statistiques
    Route::get('files/stats', [FileController::class, 'stats']);

    // Mise à jour et suppression (propriétaire ou admin)
    Route::put('files/{id}', [FileController::class, 'update'])
        ->middleware('throttle:60,1');
    Route::delete('files/{id}', [FileController::class, 'destroy'])
        ->middleware('throttle:30,1');
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
    Route::post('quizzes/{id}/start', [QuizController::class, 'startAttempt'])
        ->middleware('throttle:300,1');
    Route::post('quiz-attempts/{id}/submit', [QuizController::class, 'submitAttempt'])
        ->middleware('throttle:60,1');

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
// NOTE: Les routes notifications sont définies plus bas (ligne ~600)
// avec NotificationsController

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

// ============================================
// TEACHER STATS - Statistiques enseignant
// ============================================
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur'])->prefix('teacher')->group(function () {
    // Statistiques de l'enseignant connecté
    Route::get('/stats', [TeacherStatsController::class, 'getStats']);
});

// ============================================
// LMS DATA (Classes & Matières) - Routes protégées
// ============================================
use App\Http\Controllers\API\LMSDataController;

Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('lms')->group(function () {
    // Détails complets d'une classe
    Route::get('/classes/{classeId}', [LMSDataController::class, 'classeDetails'])
        ->name('lms.classes.details');

    // Étudiants d'une classe
    Route::get('/classes/{classeId}/etudiants', [LMSDataController::class, 'classeEtudiants'])
        ->name('lms.classes.etudiants');

    // Détails complets d'une matière
    Route::get('/matieres/{matiereId}', [LMSDataController::class, 'matiereDetails'])
        ->name('lms.matieres.details');

    // Liste des enseignants (NOUVELLE VERSION: depuis KLASSCI externe + cache)
    Route::get('/enseignants', [LMSDataController::class, 'getEnseignantsFromKlassci'])
        ->name('lms.enseignants.list');
});

// Routes admin/coordinateur uniquement
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:admin,coordinateur'])->prefix('admin')->group(function () {
    // Liste toutes les matières avec combinaisons complètes
    Route::get('/matieres', [LMSDataController::class, 'adminMatieresList'])
        ->name('admin.matieres.list');
});

// Retour au groupe /lms pour les autres routes
Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('lms')->group(function () {

    // ============================================
    // VISIOCONFÉRENCE
    // ============================================

    // Séances à venir (pré-création rooms)
    Route::get('/seances/upcoming', [LMSDataController::class, 'upcomingSeances'])
        ->name('lms.seances.upcoming');

    // Historique des séances (séances ayant eu une visio) - DOIT être AVANT {seanceId}
    Route::get('/seances/history', [LMSDataController::class, 'getSeancesHistory'])
        ->name('lms.seances.history')
        ->middleware('role:enseignant,coordinateur,superAdmin');

    // Présences détaillées d'une séance
    Route::get('/seances/{seanceId}/attendances', [LMSDataController::class, 'getSeanceAttendances'])
        ->name('lms.seances.attendances');

    // Suppression d'une séance (soft delete)
    Route::delete('/seances/{seanceId}', [LMSDataController::class, 'deleteSeance'])
        ->name('lms.seances.delete')
        ->middleware('role:enseignant,coordinateur,superAdmin');

    // Détails complets d'une séance (avec infos visio)
    Route::get('/seances/{seanceId}/details', [LMSDataController::class, 'seanceDetails'])
        ->name('lms.seances.details');

    // Participants autorisés pour une séance
    Route::get('/seances/{seanceId}/participants', [LMSDataController::class, 'seanceParticipants'])
        ->name('lms.seances.participants');

    // Valider l'accès d'un participant
    Route::post('/seances/{seanceId}/validate-participant', [LMSDataController::class, 'validateParticipant'])
        ->name('lms.seances.validate-participant');

    // Toggle visio pour séance (coordinateurs uniquement)
    Route::post('/seances/{seanceId}/toggle-visio', [LMSDataController::class, 'toggleVisioSeance'])
        ->name('lms.seances.toggle-visio')
        ->middleware('role:coordinateur,superAdmin');

    // Synchroniser les attendances depuis une session vidéo
    Route::post('/attendances/from-video-session', [LMSDataController::class, 'syncAttendancesFromVideoSession'])
        ->name('lms.attendances.from-video-session');

    // Historique des présences (accessible même si séances archivées)
    Route::get('/attendance/history', [LMSDataController::class, 'getAttendanceHistory'])
        ->name('lms.attendance.history');

    // Matières de l'enseignant connecté avec statistiques enrichies
    Route::get('/teacher/my-matieres', [LMSDataController::class, 'myMatieres'])
        ->name('lms.teacher.my-matieres')
        ->middleware('role:enseignant,coordinateur');

    // Séances de l'enseignant connecté
    Route::get('/seances/my-teaching', [LMSDataController::class, 'myTeachingSeances'])
        ->name('lms.seances.my-teaching')
        ->middleware('role:enseignant,coordinateur');

    // Séances de l'étudiant connecté
    Route::get('/seances/my-classes', [LMSDataController::class, 'myClassesSeances'])
        ->name('lms.seances.my-classes');

    // Actions visio enseignant
    Route::post('/seances/{seanceId}/activate-visio', [LMSDataController::class, 'activateVisio'])
        ->name('lms.seances.activate-visio')
        ->middleware('role:enseignant,coordinateur');

    Route::post('/seances/{seanceId}/deactivate-visio', [LMSDataController::class, 'deactivateVisio'])
        ->name('lms.seances.deactivate-visio')
        ->middleware('role:enseignant');

    Route::post('/seances/{seanceId}/start-visio', [LMSDataController::class, 'startVisio'])
        ->name('lms.seances.start-visio')
        ->middleware('role:enseignant,coordinateur');

    Route::post('/seances/{seanceId}/end-visio', [LMSDataController::class, 'endVisio'])
        ->name('lms.seances.end-visio')
        ->middleware('role:enseignant,coordinateur');

    // Étudiant rejoint visio
    Route::post('/seances/{seanceId}/join', [LMSDataController::class, 'joinVisio'])
        ->name('lms.seances.join')
        ->middleware('throttle:300,1');

    // Étudiant quitte visio
    Route::post('/seances/{seanceId}/leave', [LMSDataController::class, 'leaveVisio'])
        ->name('lms.seances.leave')
        ->middleware('throttle:300,1');

    // Heartbeat participant (ping d'activité) - Rate limited to 10000/min per user
    Route::post('/seances/{seanceId}/heartbeat', [LMSDataController::class, 'heartbeatVisio'])
        ->name('lms.seances.heartbeat')
        ->middleware('throttle:10000,1');

    // Liste des participants à une visio
    Route::get('/seances/{seanceId}/participants', [LMSDataController::class, 'getVisioParticipants'])
        ->name('lms.seances.participants');

    // Masquer une séance (étudiant uniquement)
    Route::post('/seances/{seanceId}/hide', [LMSDataController::class, 'hideSeance'])
        ->name('lms.seances.hide')
        ->middleware('role:etudiant');

    // Réafficher une séance (étudiant uniquement)
    Route::post('/seances/{seanceId}/unhide', [LMSDataController::class, 'unhideSeance'])
        ->name('lms.seances.unhide')
        ->middleware('role:etudiant');

    // ============================================
    // NOTIFICATIONS
    // ============================================

    // Préférences de notification d'un utilisateur
    Route::get('/notifications/preferences/{userId}', [LMSDataController::class, 'getNotificationPreferences'])
        ->name('lms.notifications.preferences');

    // Envoyer rappel de séance
    Route::post('/notifications/send-session-reminder', [LMSDataController::class, 'sendSessionReminder'])
        ->name('lms.notifications.send-session-reminder');
});

// ============================================
// EVALUATIONS - Routes protégées
// ============================================
use App\Http\Controllers\API\EvaluationController;

// Routes accessibles à tous les utilisateurs authentifiés
Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function () {
    // Liste et consultation des évaluations
    Route::get('evaluations', [EvaluationController::class, 'index']);

    // Évaluations de l'étudiant connecté (DOIT ÊTRE AVANT evaluations/{id})
    Route::get('evaluations/student', [EvaluationController::class, 'myEvaluations']);

    // Évaluations disponibles pour un étudiant spécifique (enseignants)
    Route::get('evaluations/student/{klassciEtudiantId}', [EvaluationController::class, 'studentEvaluations']);

    // Récupérer une évaluation spécifique (APRÈS les routes spécifiques)
    Route::get('evaluations/{id}', [EvaluationController::class, 'show']);

    // Démarrer et soumettre une évaluation
    Route::post('evaluations/{id}/start', [EvaluationController::class, 'startEvaluation'])
        ->middleware('throttle:300,1');
    Route::post('evaluations/{id}/submit', [EvaluationController::class, 'submitEvaluation'])
        ->middleware('throttle:60,1');

    // Récupérer la soumission de l'étudiant connecté
    Route::get('evaluations/{id}/my-submission', [EvaluationController::class, 'getMySubmission']);

    // État temporel en temps réel
    Route::get('evaluations/{id}/time-status', [EvaluationController::class, 'getTimeStatus']);

    // Notes de l'étudiant groupées par matière
    Route::get('my-grades', [EvaluationController::class, 'myGrades']);
});

// Routes enseignants/coordinateurs uniquement
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur,admin'])->group(function () {
    // CRUD des évaluations
    Route::post('evaluations', [EvaluationController::class, 'store']);
    Route::match(['put', 'patch'], 'evaluations/{id}', [EvaluationController::class, 'update']);
    Route::delete('evaluations/{id}', [EvaluationController::class, 'destroy']);

    // Publication
    Route::post('evaluations/{id}/publish', [EvaluationController::class, 'publish']);

    // Prévisualisation enseignant (avant publication)
    Route::get('evaluations/{id}/preview', [EvaluationController::class, 'preview']);

    // Soumissions et résultats
    Route::get('evaluations/{id}/submissions', [EvaluationController::class, 'getSubmissions']);
    Route::post('evaluations/{id}/sync-notes', [EvaluationController::class, 'syncNotesToKlassci']);

    // Synchronisation vers KLASSCI
    Route::post('evaluations/{id}/sync-to-klassci', [EvaluationController::class, 'syncToKlassci']);
});

// Routes admin/coordinateur/enseignant pour résultats d'évaluations
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur,superAdmin'])->group(function () {
    // Résultats détaillés d'une évaluation avec tous les étudiants de la classe
    Route::get('evaluations/{id}/results-by-class', [EvaluationController::class, 'getResultsByClass']);
});

// ============================================
// ADMIN ANALYTICS - Routes protégées (admin/coordinateur uniquement)
// ============================================
Route::middleware(['auth:sanctum', 'role:coordinateur,superAdmin'])->prefix('admin/analytics')->group(function () {
    // Tendances d'activité (graphes)
    Route::get('/activity-trends', [AdminAnalyticsController::class, 'getActivityTrends']);

    // Métriques système globales
    Route::get('/system-metrics', [AdminAnalyticsController::class, 'getSystemMetrics']);

    // Tâches en attente
    Route::get('/pending-tasks', [AdminAnalyticsController::class, 'getPendingTasks']);

    // Utilisateurs récents
    Route::get('/recent-users', [AdminAnalyticsController::class, 'getRecentUsers']);
});

// ============================================
// REPORTS - Génération PDF (admin/coordinateur uniquement)
// ============================================
Route::middleware(['auth:sanctum', 'role:coordinateur,superAdmin'])->prefix('admin/reports')->group(function () {
    // Rapport de présences - Rate limited: 30/min (resource intensive)
    Route::post('/attendance', [ReportController::class, 'generateAttendanceReport'])
        ->middleware('throttle:30,1');

    // Rapport de notes - Rate limited: 30/min (resource intensive)
    Route::post('/grades', [ReportController::class, 'generateGradesReport'])
        ->middleware('throttle:30,1');

    // Rapport d'activité système - Rate limited: 30/min (resource intensive)
    Route::post('/activity', [ReportController::class, 'generateActivityReport'])
        ->middleware('throttle:30,1');
});

// ============================================
// NOTIFICATIONS - Gestion des notifications
// ============================================
Route::middleware(['auth:sanctum'])->prefix('notifications')->group(function () {
    // Récupérer toutes les notifications (paginées)
    Route::get('/', [NotificationsController::class, 'index']);

    // Récupérer le nombre de notifications non lues
    Route::get('/unread-count', [NotificationsController::class, 'unreadCount']);

    // Récupérer les notifications récentes (pour widget)
    Route::get('/recent', [NotificationsController::class, 'recent']);

    // Marquer une notification comme lue
    Route::post('/{id}/mark-as-read', [NotificationsController::class, 'markAsRead']);

    // Marquer toutes les notifications comme lues
    Route::post('/mark-all-as-read', [NotificationsController::class, 'markAllAsRead']);

    // Supprimer une notification
    Route::delete('/{id}', [NotificationsController::class, 'delete']);

    // Supprimer toutes les notifications lues
    Route::delete('/read/all', [NotificationsController::class, 'deleteAllRead']);
});

// Routes admin pour les notifications
Route::middleware(['auth:sanctum', 'role:coordinateur,superAdmin'])->prefix('admin/notifications')->group(function () {
    // Créer une notification manuelle
    Route::post('/create', [NotificationsController::class, 'create']);

    // Statistiques notifications
    Route::get('/stats', [NotificationsController::class, 'stats']);
});

// ============================================
// INSTITUTION MANAGEMENT - supradmin uniquement
// ============================================
Route::middleware(['auth:sanctum', 'role:supradmin'])
    ->prefix('admin/institutions')
    ->group(function () {
        Route::get('/', [InstitutionController::class, 'index']);
        Route::get('/{id}', [InstitutionController::class, 'show']);
        Route::post('/', [InstitutionController::class, 'store']);
        Route::put('/{id}', [InstitutionController::class, 'update']);
        Route::patch('/{id}/toggle', [InstitutionController::class, 'toggle']);
        Route::post('/{id}/test-connection', [InstitutionController::class, 'testConnection']);
        Route::delete('/{id}', [InstitutionController::class, 'destroy']);
    });

// ============================================
// SEARCH - Recherche globale
// ============================================
Route::middleware(['auth:sanctum'])->prefix('search')->group(function () {
    // Recherche globale
    Route::get('/', [SearchController::class, 'globalSearch']);

    // Suggestions autocomplete
    Route::get('/suggestions', [SearchController::class, 'suggestions']);

    // Historique de recherche
    Route::get('/history', [SearchController::class, 'searchHistory']);

    // Sauvegarder dans l'historique
    Route::post('/history', [SearchController::class, 'saveSearchHistory']);
});
