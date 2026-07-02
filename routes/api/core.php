<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\Proxy\ProxyAcademicController;
use App\Http\Controllers\API\Proxy\ProxyDashboardController;
use App\Http\Controllers\API\Proxy\ProxyOrganisationController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\AdminController;
use App\Http\Controllers\API\ConfigurationController;
use App\Http\Controllers\API\IntegrationController;

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
