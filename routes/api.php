<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\Proxy\ProxyAcademicController;
use App\Http\Controllers\API\Proxy\ProxyDashboardController;
use App\Http\Controllers\API\Proxy\ProxyOrganisationController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\AdminAnalyticsController;
use App\Http\Controllers\API\ReportController;
use App\Http\Controllers\API\NotificationsController;
use App\Http\Controllers\API\SearchController;
use App\Http\Controllers\API\TeacherStatsController;
use App\Http\Controllers\API\InstitutionController;
use App\Http\Controllers\API\AdminController;
use App\Http\Controllers\API\ConfigurationController;
use App\Http\Controllers\API\IntegrationController;

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
    // Login: 10 attempts/minute/IP — aligned with Auth0 / Cloudflare defaults
    // for login endpoints (cf. OWASP Authentication Cheat Sheet: 3-10 attempts
    // before lockout). Reduces brute-force throughput by 83% vs. previous 60/min
    // while still tolerating legitimate retries (humans rarely need more than
    // 5-6 attempts to type their password correctly).
    //
    // Future hardening (post-CI security pipeline): switch to a named
    // RateLimiter::for('login') with composite key (IP + username) + exponential
    // backoff to defeat credential stuffing distributed across IPs.
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
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
    ->middleware(['auth:sanctum', 'klassci.sync', 'role:coordinateur,superAdmin,supradmin', 'throttle:proxy'])
    ->group(function () {
        // Test de connexion KLASSCI — réservé aux admins
        Route::get('/test-connection', [ProxyOrganisationController::class, 'testConnection']);
    });

// ============================================
// PROXY KLASSCI - Données organisationnelles (SÉCURISÉ)
// Routes authentifiées pour tous les rôles
// ============================================
Route::prefix('proxy')
    ->middleware(['auth:sanctum', 'klassci.sync', 'throttle:proxy'])
    ->group(function () {
        // Structure organisationnelle
        Route::get('/structure', [ProxyOrganisationController::class, 'structure']);
        Route::get('/filieres', [ProxyOrganisationController::class, 'filieres']);
        Route::get('/niveaux-etudes', [ProxyOrganisationController::class, 'niveauxEtudes']);

        // Classes et étudiants
        Route::get('/classes', [ProxyOrganisationController::class, 'classes']);
        Route::get('/classes/{id}/etudiants', [ProxyOrganisationController::class, 'etudiants']);

        // Matières et enseignants
        Route::get('/matieres', [ProxyOrganisationController::class, 'matieres']);
        Route::get('/matieres/{id}', [ProxyOrganisationController::class, 'matiereDetails']);
        Route::get('/enseignants', [ProxyOrganisationController::class, 'enseignants']);

        // Emploi du temps
        Route::get('/emploi-temps', [ProxyAcademicController::class, 'emploiTemps']);

        // Évaluations - Lecture
        Route::get('/evaluations', [ProxyAcademicController::class, 'evaluations']);
    });

// ============================================
// PROXY KLASSCI - Routes ENSEIGNANTS uniquement
// ============================================
Route::prefix('proxy')
    ->middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur', 'throttle:proxy-write'])
    ->group(function () {

    // Sauvegarder les notes (Enseignants/Coordinateurs uniquement)
    Route::post('/evaluations/{id}/notes', [ProxyAcademicController::class, 'saveNotes']);

    // Sauvegarder les présences (Enseignants/Coordinateurs uniquement)
    Route::post('/cours/{id}/presences', [ProxyAcademicController::class, 'savePresences']);

    // Mettre à jour statut cours (Enseignants/Coordinateurs uniquement)
    Route::put('/cours/{id}/statut', [ProxyAcademicController::class, 'updateCoursStatut']);
});

// ============================================
// ADMIN - Gestion des utilisateurs et configuration
// ============================================
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:coordinateur,superAdmin'])->group(function () {
    Route::post('users', [AdminController::class, 'createUser'])
        ->middleware('throttle:30,1');
    Route::put('users/{user}', [AdminController::class, 'updateUser'])
        ->middleware('throttle:30,1');
    Route::delete('users/{user}', [AdminController::class, 'deleteUser'])
        ->middleware('throttle:30,1');
});

// ============================================
// CONFIGURATION - Gestion de la configuration système
// ============================================
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:superAdmin'])->group(function () {
    Route::get('configuration', [ConfigurationController::class, 'get'])
        ->middleware('throttle:60,1');
    Route::put('configuration', [ConfigurationController::class, 'update'])
        ->middleware('throttle:30,1');
    Route::delete('configuration', [ConfigurationController::class, 'delete'])
        ->middleware('throttle:30,1');
});

// ============================================
// INTEGRATIONS - Services tiers
// ============================================
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:superAdmin'])->prefix('integrations')->group(function () {
    Route::post('connect', [IntegrationController::class, 'connect'])
        ->middleware('throttle:30,1');
    Route::post('authorize', [IntegrationController::class, 'authorize'])
        ->middleware('throttle:30,1');
    Route::post('test', [IntegrationController::class, 'testConnection'])
        ->middleware('throttle:60,1');
    Route::post('disconnect', [IntegrationController::class, 'disconnect'])
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
        Route::get('/me/dashboard', [ProxyDashboardController::class, 'studentDashboard']);

        // Dashboard enseignant (réservé aux enseignants/coordinateurs)
        Route::get('/me/teacher-dashboard', [ProxyDashboardController::class, 'teacherDashboard'])
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
// Split SRP : CRUD + Attempts dans 2 controllers thin
// ============================================
use App\Http\Controllers\API\KnowledgeCheckCrudController;
use App\Http\Controllers\API\KnowledgeCheckAttemptController;

Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function () {
    // Liste des quiz d'un chapitre
    Route::get('knowledge-checks', [KnowledgeCheckCrudController::class, 'index']);
    // Quiz par chapitre (doit être AVANT {id} pour éviter le conflit)
    Route::get('knowledge-checks/chapter/{chapterId}', [KnowledgeCheckCrudController::class, 'getByChapter']);
    Route::get('knowledge-checks/{id}', [KnowledgeCheckCrudController::class, 'show']);

    // Tentatives (étudiants)
    Route::post('knowledge-checks/{id}/start', [KnowledgeCheckAttemptController::class, 'startAttempt'])
        ->middleware('throttle:300,1');
    Route::post('knowledge-checks/{id}/submit', [KnowledgeCheckAttemptController::class, 'submitAttempt'])
        ->middleware('throttle:60,1');
    Route::get('knowledge-checks/{id}/my-attempts', [KnowledgeCheckAttemptController::class, 'myAttempts']);

    // CRUD (enseignants/admins)
    Route::post('knowledge-checks', [KnowledgeCheckCrudController::class, 'store']);
    Route::put('knowledge-checks/{id}', [KnowledgeCheckCrudController::class, 'update']);
    Route::delete('knowledge-checks/{id}', [KnowledgeCheckCrudController::class, 'destroy']);
});

// ============================================
// LESSONS (Cours/Leçons) - Routes protégées
// ============================================
use App\Http\Controllers\API\Lesson\LessonCrudController;
use App\Http\Controllers\API\Lesson\LessonProgressController;

// Routes accessibles à tous les utilisateurs authentifiés
Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function () {
    // Liste et consultation des cours
    Route::get('lessons', [LessonCrudController::class, 'index']);
    Route::get('lessons/my-courses', [LessonCrudController::class, 'myCourses']); // Cours de l'étudiant avec filtres
    Route::get('lessons/{id}', [LessonCrudController::class, 'show']);

    // Progression (Tous peuvent voir leur progression)
    Route::get('lessons/{id}/progress', [LessonProgressController::class, 'getProgress']);
    Route::post('lessons/{id}/progress', [LessonProgressController::class, 'updateProgress'])
        ->middleware('throttle:300,1');
    Route::post('lessons/{id}/complete', [LessonProgressController::class, 'markComplete'])
        ->middleware('throttle:300,1');
    Route::post('lessons/{id}/rating', [LessonProgressController::class, 'rate'])
        ->middleware('throttle:300,1');
});

// Routes enseignants/coordinateurs/admins
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur,admin'])->group(function () {
    // CRUD des cours
    Route::post('lessons', [LessonCrudController::class, 'store'])
        ->middleware('throttle:60,1');
    Route::match(['put', 'patch'], 'lessons/{id}', [LessonCrudController::class, 'update'])
        ->middleware('throttle:30,1');
    Route::delete('lessons/{id}', [LessonCrudController::class, 'destroy'])
        ->middleware('throttle:30,1');

    // Actions spéciales
    Route::post('lessons/{id}/publish', [LessonCrudController::class, 'publish'])
        ->middleware('throttle:100,1');
    Route::post('lessons/{id}/unpublish', [LessonCrudController::class, 'unpublish'])
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

// Routes accessibles à tous les utilisateurs authentifiés.
// IMPORTANT: les routes statiques (`files/upload`, `files/stats`) DOIVENT être
// déclarées AVANT `files/{id}` — sinon Laravel matche `{id}=upload|stats`
// dans l'ordre et la route statique devient inaccessible (404).
Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function () {
    // Liste
    Route::get('files', [FileController::class, 'index']);

    // Upload de fichiers (Tous peuvent uploader) - Rate limited: 60/min (with size limit)
    Route::post('files/upload', [FileController::class, 'upload'])
        ->middleware('throttle:60,1');

    // Statistiques (déclarée avant `files/{id}` pour éviter le matching de `{id}=stats`)
    Route::get('files/stats', [FileController::class, 'stats']);

    // Consultation par id (après les routes statiques)
    Route::get('files/{id}', [FileController::class, 'show']);
    Route::get('files/{id}/download', [FileController::class, 'download'])->name('api.files.download');

    // Mise à jour et suppression (propriétaire ou admin)
    Route::put('files/{file}', [FileController::class, 'update'])
        ->middleware('throttle:60,1');
    Route::delete('files/{file}', [FileController::class, 'destroy'])
        ->middleware('throttle:30,1');
});

// ============================================
// QUIZZES - Routes protégées
// ============================================
use App\Http\Controllers\API\Quiz\QuizAttemptStudentController;
use App\Http\Controllers\API\Quiz\QuizAttemptTeacherController;
use App\Http\Controllers\API\Quiz\QuizCrudController;

// Routes accessibles à tous les utilisateurs authentifiés
Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function () {
    // Liste et consultation des quiz
    Route::get('quizzes', [QuizCrudController::class, 'index']);
    Route::get('quizzes/{quiz}', [QuizCrudController::class, 'show']);

    // Démarrer et soumettre une tentative
    Route::post('quizzes/{quiz}/start', [QuizAttemptStudentController::class, 'startAttempt'])
        ->middleware('throttle:300,1');
    Route::post('quiz-attempts/{id}/submit', [QuizAttemptStudentController::class, 'submitAttempt'])
        ->middleware('throttle:60,1');

    // NOUVEAU: Timer et sauvegarde de progression
    Route::get('quiz-attempts/{id}/time-remaining', [QuizAttemptStudentController::class, 'checkTimeRemaining']);
    Route::post('quiz-attempts/{id}/save-progress', [QuizAttemptStudentController::class, 'saveProgress']);

    // Consulter une tentative
    Route::get('quiz-attempts/{id}', [QuizAttemptStudentController::class, 'showAttempt']);
});

// Routes enseignants/coordinateurs/admins
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur,admin'])->group(function () {
    // CRUD des quiz
    Route::post('quizzes', [QuizCrudController::class, 'store']);
    Route::put('quizzes/{quiz}', [QuizCrudController::class, 'update']);
    Route::delete('quizzes/{quiz}', [QuizCrudController::class, 'destroy']);

    // Publication
    Route::post('quizzes/{quiz}/publish', [QuizCrudController::class, 'publish']);

    // Gestion des tentatives
    Route::get('quizzes/{quiz}/attempts', [QuizAttemptTeacherController::class, 'getAttempts']);
    Route::post('quiz-attempts/{id}/grade', [QuizAttemptTeacherController::class, 'gradeAttempt']);
});

// ============================================
// NOTIFICATIONS - Routes protégées
// ============================================
// NOTE: Les routes notifications sont définies plus bas (ligne ~600)
// avec NotificationsController

// ============================================
// DASHBOARD - Routes protégées
// ============================================
use App\Http\Controllers\API\Dashboard\DashboardAdminController;
use App\Http\Controllers\API\Dashboard\DashboardStudentController;
use App\Http\Controllers\API\Dashboard\DashboardTeacherController;

Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('dashboard')->group(function () {
    // Dashboard étudiant (tous les utilisateurs authentifiés)
    Route::get('/student', [DashboardStudentController::class, 'student']);

    // Dashboard enseignant (enseignants et coordinateurs uniquement)
    Route::get('/teacher', [DashboardTeacherController::class, 'teacher'])
        ->middleware('role:enseignant,coordinateur');

    // Statistiques globales (coordinateurs et admin uniquement)
    Route::get('/stats', [DashboardAdminController::class, 'stats'])
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
// LMS — Routes protégées (god-object LMSDataController split en 7 controllers
// + 2 services partagés. Spec : .claude/specs/lms-data-controller-split/).
// ============================================
use App\Http\Controllers\API\LMS\LMSAttendancesController;
use App\Http\Controllers\API\LMS\LMSClassesController;
use App\Http\Controllers\API\LMS\LMSEnseignantsController;
use App\Http\Controllers\API\LMS\LMSMatieresAdminController;
use App\Http\Controllers\API\LMS\LMSMatieresQueryController;
use App\Http\Controllers\API\LMS\LMSNotificationsPreferencesController;
use App\Http\Controllers\API\LMS\LMSSeanceDetailsController;
use App\Http\Controllers\API\LMS\LMSSeanceParticipantMutationController;
use App\Http\Controllers\API\LMS\LMSSeanceVisibilityMutationController;
use App\Http\Controllers\API\LMS\LMSSeancesHistoryController;
use App\Http\Controllers\API\LMS\LMSSeancesListController;
use App\Http\Controllers\API\LMS\LMSVisioLifecycleController;
use App\Http\Controllers\API\LMS\LMSVisioParticipantController;

Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('lms')->group(function () {
    // Détails complets d'une classe
    Route::get('/classes/{classeId}', [LMSClassesController::class, 'classeDetails'])
        ->name('lms.classes.details');

    // Étudiants d'une classe
    Route::get('/classes/{classeId}/etudiants', [LMSClassesController::class, 'classeEtudiants'])
        ->name('lms.classes.etudiants');

    // Détails complets d'une matière
    Route::get('/matieres/{matiereId}', [LMSMatieresQueryController::class, 'matiereDetails'])
        ->name('lms.matieres.details');

    // Liste des enseignants (depuis KLASSCI externe + cache local 10 min)
    Route::get('/enseignants', [LMSEnseignantsController::class, 'getEnseignantsFromKlassci'])
        ->name('lms.enseignants.list');
});

// Routes admin/coordinateur uniquement
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:admin,coordinateur'])->prefix('admin')->group(function () {
    // Liste toutes les matières avec combinaisons complètes
    Route::get('/matieres', [LMSMatieresAdminController::class, 'adminMatieresList'])
        ->name('admin.matieres.list');
});

// Retour au groupe /lms pour les autres routes
Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('lms')->group(function () {

    // ============================================
    // VISIOCONFÉRENCE
    // ============================================

    // Séances à venir (pré-création rooms)
    Route::get('/seances/upcoming', [LMSSeancesListController::class, 'upcomingSeances'])
        ->name('lms.seances.upcoming');

    // Historique des séances (séances ayant eu une visio) - DOIT être AVANT {seanceId}
    Route::get('/seances/history', [LMSSeancesHistoryController::class, 'getSeancesHistory'])
        ->name('lms.seances.history')
        ->middleware('role:enseignant,coordinateur,superAdmin');

    // Présences détaillées d'une séance
    Route::get('/seances/{seanceId}/attendances', [LMSAttendancesController::class, 'getSeanceAttendances'])
        ->name('lms.seances.attendances');

    // Suppression d'une séance (soft delete)
    Route::delete('/seances/{seanceId}', [LMSSeanceVisibilityMutationController::class, 'deleteSeance'])
        ->name('lms.seances.delete')
        ->middleware('role:enseignant,coordinateur,superAdmin');

    // Détails complets d'une séance (avec infos visio)
    Route::get('/seances/{seanceId}/details', [LMSSeanceDetailsController::class, 'seanceDetails'])
        ->name('lms.seances.details');

    // Participants autorisés pour une séance
    Route::get('/seances/{seanceId}/participants', [LMSSeanceDetailsController::class, 'seanceParticipants'])
        ->name('lms.seances.participants');

    // Valider l'accès d'un participant
    Route::post('/seances/{seanceId}/validate-participant', [LMSSeanceParticipantMutationController::class, 'validateParticipant'])
        ->name('lms.seances.validate-participant');

    // Toggle visio pour séance (coordinateurs uniquement)
    Route::post('/seances/{seanceId}/toggle-visio', [LMSSeanceVisibilityMutationController::class, 'toggleVisioSeance'])
        ->name('lms.seances.toggle-visio')
        ->middleware('role:coordinateur,superAdmin');

    // Synchroniser les attendances depuis une session vidéo
    Route::post('/attendances/from-video-session', [LMSAttendancesController::class, 'syncAttendancesFromVideoSession'])
        ->name('lms.attendances.from-video-session');

    // Historique des présences (accessible même si séances archivées)
    Route::get('/attendance/history', [LMSAttendancesController::class, 'getAttendanceHistory'])
        ->name('lms.attendance.history');

    // Matières de l'enseignant connecté avec statistiques enrichies
    Route::get('/teacher/my-matieres', [LMSMatieresQueryController::class, 'myMatieres'])
        ->name('lms.teacher.my-matieres')
        ->middleware('role:enseignant,coordinateur');

    // Séances de l'enseignant connecté
    Route::get('/seances/my-teaching', [LMSSeancesListController::class, 'myTeachingSeances'])
        ->name('lms.seances.my-teaching')
        ->middleware('role:enseignant,coordinateur');

    // Séances de l'étudiant connecté
    Route::get('/seances/my-classes', [LMSSeancesListController::class, 'myClassesSeances'])
        ->name('lms.seances.my-classes');

    // Actions visio enseignant
    Route::post('/seances/{seanceId}/activate-visio', [LMSVisioLifecycleController::class, 'activateVisio'])
        ->name('lms.seances.activate-visio')
        ->middleware('role:enseignant,coordinateur');

    Route::post('/seances/{seanceId}/deactivate-visio', [LMSVisioLifecycleController::class, 'deactivateVisio'])
        ->name('lms.seances.deactivate-visio')
        ->middleware('role:enseignant');

    Route::post('/seances/{seanceId}/start-visio', [LMSVisioLifecycleController::class, 'startVisio'])
        ->name('lms.seances.start-visio')
        ->middleware('role:enseignant,coordinateur');

    Route::post('/seances/{seanceId}/end-visio', [LMSVisioLifecycleController::class, 'endVisio'])
        ->name('lms.seances.end-visio')
        ->middleware('role:enseignant,coordinateur');

    // Étudiant rejoint visio
    Route::post('/seances/{seanceId}/join', [LMSVisioParticipantController::class, 'joinVisio'])
        ->name('lms.seances.join')
        ->middleware('throttle:300,1');

    // Étudiant quitte visio
    Route::post('/seances/{seanceId}/leave', [LMSVisioParticipantController::class, 'leaveVisio'])
        ->name('lms.seances.leave')
        ->middleware('throttle:300,1');

    // Heartbeat participant (ping d'activité) - Rate limited to 10000/min per user
    Route::post('/seances/{seanceId}/heartbeat', [LMSVisioParticipantController::class, 'heartbeatVisio'])
        ->name('lms.seances.heartbeat')
        ->middleware('throttle:10000,1');

    // Liste des participants connectés à une visio.
    // REQ-4 du spec : route renommée `/visio-participants` pour résoudre le
    // conflit avec `lms.seances.participants` (LMSSeancesController, ligne 530)
    // qui matchait toujours en premier — la route legacy était INACCESSIBLE.
    Route::get('/seances/{seanceId}/visio-participants', [LMSVisioParticipantController::class, 'getVisioParticipants'])
        ->name('lms.seances.visio-participants');

    // Masquer une séance (étudiant uniquement)
    Route::post('/seances/{seanceId}/hide', [LMSSeanceVisibilityMutationController::class, 'hideSeance'])
        ->name('lms.seances.hide')
        ->middleware('role:etudiant');

    // Réafficher une séance (étudiant uniquement)
    Route::post('/seances/{seanceId}/unhide', [LMSSeanceVisibilityMutationController::class, 'unhideSeance'])
        ->name('lms.seances.unhide')
        ->middleware('role:etudiant');

    // ============================================
    // NOTIFICATIONS
    // ============================================

    // Préférences de notification d'un utilisateur
    Route::get('/notifications/preferences/{userId}', [LMSNotificationsPreferencesController::class, 'getNotificationPreferences'])
        ->name('lms.notifications.preferences');

    // Envoyer rappel de séance
    Route::post('/notifications/send-session-reminder', [LMSNotificationsPreferencesController::class, 'sendSessionReminder'])
        ->name('lms.notifications.send-session-reminder');
});

// ============================================
// EVALUATIONS - Routes protégées
// ============================================
// Refactor #152/#153 — god-controller `EvaluationController` (1676 lignes)
// split en 4 controllers SRP sous `App\Http\Controllers\API\Evaluation\`.
use App\Http\Controllers\API\Evaluation\EvaluationCrudController;
use App\Http\Controllers\API\Evaluation\EvaluationKlassciSyncController;
use App\Http\Controllers\API\Evaluation\EvaluationTeacherController;
use App\Http\Controllers\API\Evaluation\Student\EvaluationStudentAttemptController;
use App\Http\Controllers\API\Evaluation\Student\EvaluationStudentListController;
use App\Http\Controllers\API\Evaluation\Student\EvaluationStudentSubmissionController;

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
    Route::post('evaluations/{id}/start', [EvaluationStudentAttemptController::class, 'startEvaluation'])
        ->middleware('throttle:300,1');
    Route::post('evaluations/{id}/submit', [EvaluationStudentAttemptController::class, 'submitEvaluation'])
        ->middleware('throttle:60,1');

    // Récupérer la soumission de l'étudiant connecté
    Route::get('evaluations/{id}/my-submission', [EvaluationStudentSubmissionController::class, 'getMySubmission']);

    // État temporel en temps réel
    Route::get('evaluations/{id}/time-status', [EvaluationStudentAttemptController::class, 'getTimeStatus']);

    // Notes de l'étudiant groupées par matière
    Route::get('my-grades', [EvaluationStudentListController::class, 'myGrades']);
});

// Routes enseignants/coordinateurs uniquement
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur,admin'])->group(function () {
    // CRUD des évaluations
    Route::post('evaluations', [EvaluationCrudController::class, 'store']);
    Route::match(['put', 'patch'], 'evaluations/{id}', [EvaluationCrudController::class, 'update']);
    Route::delete('evaluations/{id}', [EvaluationCrudController::class, 'destroy']);

    // Publication
    Route::post('evaluations/{id}/publish', [EvaluationCrudController::class, 'publish']);

    // Prévisualisation enseignant (avant publication)
    Route::get('evaluations/{id}/preview', [EvaluationTeacherController::class, 'preview']);

    // Soumissions et résultats
    Route::get('evaluations/{id}/submissions', [EvaluationTeacherController::class, 'getSubmissions']);
    Route::post('evaluations/{id}/sync-notes', [EvaluationKlassciSyncController::class, 'syncNotesToKlassci']);

    // Synchronisation vers KLASSCI
    Route::post('evaluations/{id}/sync-klassci', [EvaluationKlassciSyncController::class, 'syncToKlassci']);
});

// Routes admin/coordinateur/enseignant pour résultats d'évaluations
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur,superAdmin'])->group(function () {
    // Résultats détaillés d'une évaluation avec tous les étudiants de la classe
    Route::get('evaluations/{id}/results-by-class', [EvaluationTeacherController::class, 'getResultsByClass']);
});

// ============================================
// ADMIN - Journal d'audit (#215) — supradmin uniquement (lecture seule)
// L'autorisation stricte supradmin est portée par ViewAuditLogRequest.
// ============================================
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    Route::get('/audit-log', [\App\Http\Controllers\API\Admin\AuditLogController::class, 'index']);
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

// Routes admin pour les notifications.
// `supradmin` added (issue #98 fix): platform manager can create cross-tenant
// notifications and view global stats; tenant isolation enforced by FormRequest
// authorize() + stats() filter logic.
Route::middleware(['auth:sanctum', 'role:coordinateur,superAdmin,supradmin'])->prefix('admin/notifications')->group(function () {
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

// ============================================
// Route de test interne — testing env uniquement
// ============================================
// Sert exclusivement à valider le pipeline d'exception handling
// (`bootstrap/app.php` withExceptions) côté tests Feature
// (cf. `tests/Unit/ExceptionHandlerTest`). N'est pas exposée en prod.
if (app()->environment('testing')) {
    Route::get('/test-throw-exception', function () {
        throw new \RuntimeException('Diagnostic exception (testing env only).');
    });
}
