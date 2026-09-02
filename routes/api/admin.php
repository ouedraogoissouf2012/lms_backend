<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AdminAnalyticsController;
use App\Http\Controllers\API\ReportController;
use App\Http\Controllers\API\NotificationsController;
use App\Http\Controllers\API\SearchController;
use App\Http\Controllers\API\InstitutionController;

// ============================================
// ADMIN - Journal d'audit (#215) — supradmin uniquement (lecture seule)
// L'autorisation stricte supradmin est portée par ViewAuditLogRequest.
// ============================================
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    Route::get('/audit-log', [\App\Http\Controllers\API\Admin\AuditLogController::class, 'index']);
});

// ============================================
// ADMIN - Liste des comptes LMS du tenant.
// Hors `klassci.sync` : cet ecran ne lit QUE la base LMS, aucun appel amont.
// Meme allow-list de roles que le CRUD `users` (routes/api/core.php).
// Le supradmin plateforme est refuse par ListUsersRequest::authorize() :
// sans institution_id, le scope tenant serait fail-open.
// ============================================
Route::middleware(['auth:sanctum', 'role:coordinateur,superAdmin'])->prefix('admin')->group(function () {
    Route::get('/users', [\App\Http\Controllers\API\AdminController::class, 'listUsers'])
        ->middleware('throttle:60,1')
        ->name('admin.users.index');
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

    Route::get('/async/{id}', [ReportController::class, 'asyncStatus'])
        ->name('admin.reports.async.status')
        ->middleware('throttle:60,1');

    Route::get('/async/{id}/download', [ReportController::class, 'asyncDownload'])
        ->name('admin.reports.async.download')
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
// #511 : `platform.supradmin` = 2ᵉ garde STRICTE (défense en profondeur) sur le
// CRUD cross-tenant le plus sensible, en plus de `role:supradmin`.
Route::middleware(['auth:sanctum', 'role:supradmin', 'platform.supradmin'])
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
Route::middleware(['auth:sanctum', 'throttle:search'])->prefix('search')->group(function () {
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
