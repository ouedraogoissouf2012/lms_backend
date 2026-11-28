<?php

use App\Http\Controllers\API\Admin\AuditLogController;
use App\Http\Controllers\API\AdminAnalyticsController;
use App\Http\Controllers\API\AdminController;
use App\Http\Controllers\API\InstitutionController;
use App\Http\Controllers\API\NotificationsController;
use App\Http\Controllers\API\ReportController;
use App\Http\Controllers\API\SchoolRequestDecisionController;
use App\Http\Controllers\API\SearchController;
use Illuminate\Support\Facades\Route;

// ============================================
// ADMIN - Journal d'audit (#215) — supradmin uniquement (lecture seule)
// L'autorisation stricte supradmin est portée par ViewAuditLogRequest.
// Route PLATEFORME (D51) : le supradmin, sans école, lit le journal de toutes les
// écoles pour diagnostiquer et corriger. L'exception est limitée à l'audit.
// ============================================
Route::middleware(['auth:sanctum', 'plateforme'])->prefix('admin')->group(function () {
    Route::get('/audit-log', [AuditLogController::class, 'index']);
});

// ============================================
// ADMIN - Liste des comptes LMS du tenant.
// Hors `klassci.sync` : cet ecran ne lit QUE la base LMS, aucun appel amont.
// Meme permission que le CRUD `users` (routes/api/core.php) : `comptes.gerer` (#1665).
// Le supradmin plateforme est refuse par ListUsersRequest::authorize() :
// sans institution_id, le scope tenant serait fail-open.
// ============================================
Route::middleware(['auth:sanctum', 'permission:comptes.gerer'])->prefix('admin')->group(function () {
    Route::get('/users', [AdminController::class, 'listUsers'])
        ->middleware('throttle:comptes-liste')
        ->name('admin.users.index');
});

// ============================================
// ADMIN ANALYTICS - Routes protégées (permission `pilotage.consulter`, #1665)
// ============================================
Route::middleware(['auth:sanctum', 'permission:pilotage.consulter'])->prefix('admin/analytics')->group(function () {
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
// REPORTS - Génération PDF (le rapport de présence : `rapports.presences` ; les autres : `pilotage.consulter`)
// ============================================
Route::middleware(['auth:sanctum', 'permission:rapports.presences'])->prefix('admin/reports')->group(function () {
    Route::post('/attendance', [ReportController::class, 'generateAttendanceReport']);
});

Route::middleware(['auth:sanctum', 'permission:pilotage.consulter'])->prefix('admin/reports')->group(function () {
    Route::post('/grades', [ReportController::class, 'generateGradesReport']);

    // Rapport d'activité système - Rate limited: 30/min (resource intensive)
    Route::post('/activity', [ReportController::class, 'generateActivityReport']);

    Route::get('/async/{id}', [ReportController::class, 'asyncStatus'])
        ->name('admin.reports.async.status')
        ->middleware('throttle:rapports-suivi');

    Route::get('/async/{id}/download', [ReportController::class, 'asyncDownload'])
        ->name('admin.reports.async.download')
        ->middleware('throttle:rapports-telechargement');
});

// ============================================
// NOTIFICATIONS - Gestion des notifications
// ============================================
Route::middleware(['auth:sanctum', 'plateforme'])->prefix('notifications')->group(function () {
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
Route::middleware(['auth:sanctum', 'permission:notifications.diffuser', 'plateforme'])->prefix('admin/notifications')->group(function () {
    // Créer une notification manuelle
    Route::post('/create', [NotificationsController::class, 'create']);

    // Statistiques notifications
    Route::get('/stats', [NotificationsController::class, 'stats']);
});

// ============================================
// INSTITUTION MANAGEMENT - supradmin uniquement
// ============================================
// #511 : `platform.supradmin` = 2ᵉ garde STRICTE (défense en profondeur) sur le
// CRUD cross-tenant le plus sensible, en plus de `permission:plateforme.etablissements`.
Route::middleware(['auth:sanctum', 'permission:plateforme.etablissements', 'platform.supradmin', 'plateforme'])
    ->prefix('admin/institutions')
    ->group(function () {
        Route::get('/', [InstitutionController::class, 'index']);
        Route::get('/{id}', [InstitutionController::class, 'show']);
        Route::post('/', [InstitutionController::class, 'store']);
        Route::put('/{id}', [InstitutionController::class, 'update']);
        Route::patch('/{id}/toggle', [InstitutionController::class, 'toggle']);
        // #818 : la bascule de mode a sa propre route, pour ne jamais être
        // l'effet de bord d'un PUT de branding.
        Route::patch('/{id}/mode', [InstitutionController::class, 'changeMode']);
        Route::post('/{id}/test-connection', [InstitutionController::class, 'testConnection']);
        Route::delete('/{id}', [InstitutionController::class, 'destroy']);
    });

// ============================================
// DEMANDES D'OUVERTURE D'ECOLE - supradmin uniquement (#803)
// ============================================
// Meme double garde que le CRUD institutions (#511) : valider une demande CREE
// un tenant et son premier compte. C'est l'ecriture cross-tenant la plus
// sensible du systeme.
Route::middleware(['auth:sanctum', 'permission:plateforme.demandes_ecole', 'platform.supradmin', 'plateforme'])
    ->prefix('admin/school-requests')
    ->group(function () {
        Route::get('/', [SchoolRequestDecisionController::class, 'index']);
        Route::post('/{schoolRequest}/validate', [SchoolRequestDecisionController::class, 'validate']);
        Route::post('/{schoolRequest}/refuse', [SchoolRequestDecisionController::class, 'refuse']);
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
        throw new RuntimeException('Diagnostic exception (testing env only).');
    });
}
