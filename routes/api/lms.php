<?php

use App\Http\Controllers\API\Dashboard\DashboardAdminController;
use App\Http\Controllers\API\Dashboard\DashboardStudentController;
use App\Http\Controllers\API\Dashboard\DashboardTeacherController;
// ============================================
// NOTIFICATIONS - Routes protégées
// ============================================
// NOTE: Les routes notifications sont définies plus bas (ligne ~600)
// avec NotificationsController

// ============================================
// DASHBOARD - Routes protégées
// ============================================
use App\Http\Controllers\API\TeacherStatsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('dashboard')->group(function () {
    // Dashboard étudiant (tous les utilisateurs authentifiés)
    Route::get('/student', [DashboardStudentController::class, 'student']);

    // Dashboard enseignant (enseignants et coordinateurs uniquement)
    Route::get('/teacher', [DashboardTeacherController::class, 'teacher'])
        ->middleware('role:enseignant,coordinateur');

    // Statistiques globales (garde `gestion`)
    Route::get('/stats', [DashboardAdminController::class, 'stats'])
        ->middleware('gestion');
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
use App\Http\Controllers\API\Admin\AdminStatisticsController;
use App\Http\Controllers\API\LMS\LMSAttendancesController;
use App\Http\Controllers\API\LMS\LMSEnseignantsController;
use App\Http\Controllers\API\LMS\LMSMatieresAdminController;
use App\Http\Controllers\API\LMS\LMSMatieresQueryController;
use App\Http\Controllers\API\LMS\LMSNotificationsPreferencesController;
use App\Http\Controllers\API\LMS\LMSSeanceCrudController;
use App\Http\Controllers\API\LMS\LMSSeanceDetailsController;
use App\Http\Controllers\API\LMS\LMSSeanceParticipantMutationController;
use App\Http\Controllers\API\LMS\LMSSeancesHistoryController;
use App\Http\Controllers\API\LMS\LMSSeancesListController;
use App\Http\Controllers\API\LMS\LMSSeanceVisibilityMutationController;
use App\Http\Controllers\API\LMS\LMSVisioConsentController;
use App\Http\Controllers\API\LMS\LMSVisioLifecycleController;
use App\Http\Controllers\API\LMS\LMSVisioParticipantController;
use App\Http\Controllers\API\LMS\LMSVisioRecordingController;
use App\Http\Controllers\API\LMS\VisioRecordingWebhookController;

Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('lms')->group(function () {

    // Détails complets d'une matière. `whereNumber` : le contrôleur type son
    // paramètre `int`, et un segment non numérique levait un TypeError AVANT
    // son `try`, rendu en 500. C'est une adresse qui n'existe pas : 404.
    Route::get('/matieres/{matiereId}', [LMSMatieresQueryController::class, 'matiereDetails'])
        ->whereNumber('matiereId')
        ->name('lms.matieres.details');

    // Liste des enseignants (depuis KLASSCI externe + cache local 10 min)
    Route::get('/enseignants', [LMSEnseignantsController::class, 'getEnseignantsFromKlassci'])
        ->name('lms.enseignants.list');
});

// Routes de gestion (garde `gestion` : coordinateur, admin, superAdmin)
Route::middleware(['auth:sanctum', 'klassci.sync', 'gestion'])->prefix('admin')->group(function () {
    // Liste toutes les matières avec combinaisons complètes
    Route::get('/matieres', [LMSMatieresAdminController::class, 'adminMatieresList'])
        ->name('admin.matieres.list');
    Route::get('/statistics', [AdminStatisticsController::class, 'show'])
        ->name('admin.statistics');
});

// Retour au groupe /lms pour les autres routes
Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('lms')->group(function () {

    // ============================================
    // VISIOCONFÉRENCE
    // ============================================

    Route::post('/seances', [LMSSeanceCrudController::class, 'store'])
        ->name('lms.seances.store')
        ->middleware('personnel');

    // Séances à venir (pré-création rooms)
    Route::get('/seances/upcoming', [LMSSeancesListController::class, 'upcomingSeances'])
        ->name('lms.seances.upcoming');

    // Historique des séances (séances ayant eu une visio) - DOIT être AVANT {seanceId}
    Route::get('/seances/history', [LMSSeancesHistoryController::class, 'getSeancesHistory'])
        ->name('lms.seances.history')
        ->middleware('personnel');

    // Présences détaillées d'une séance
    Route::get('/seances/{seanceId}/attendances', [LMSAttendancesController::class, 'getSeanceAttendances'])
        ->name('lms.seances.attendances')
        // Sécurité : liste les emails des participants -> réservé au staff (comme
        // la voisine /seances/history). Sans ce garde, un étudiant listait les
        // emails de toute séance (checkAccess ne bloque que l'enseignant non-owner).
        ->middleware('personnel');

    // Suppression d'une séance (soft delete)
    Route::delete('/seances/{seanceId}', [LMSSeanceVisibilityMutationController::class, 'deleteSeance'])
        ->name('lms.seances.delete')
        ->middleware('personnel');

    // Détails complets d'une séance (avec infos visio)
    Route::get('/seances/{seanceId}/details', [LMSSeanceDetailsController::class, 'seanceDetails'])
        ->name('lms.seances.details');

    // Valider l'accès d'un participant
    Route::post('/seances/{seanceId}/validate-participant', [LMSSeanceParticipantMutationController::class, 'validateParticipant'])
        ->name('lms.seances.validate-participant')
        ->middleware('personnel');

    // Toggle visio pour séance (coordinateurs uniquement)
    Route::post('/seances/{seanceId}/toggle-visio', [LMSSeanceVisibilityMutationController::class, 'toggleVisioSeance'])
        ->name('lms.seances.toggle-visio')
        ->middleware('gestion');

    // Synchroniser les attendances depuis une session vidéo
    Route::post('/attendances/from-video-session', [LMSAttendancesController::class, 'syncAttendancesFromVideoSession'])
        ->name('lms.attendances.from-video-session')
        ->middleware('personnel');

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
        ->middleware('role:enseignant,coordinateur');

    Route::post('/seances/{seanceId}/start-visio', [LMSVisioLifecycleController::class, 'startVisio'])
        ->name('lms.seances.start-visio')
        ->middleware('role:enseignant,coordinateur');

    Route::post('/seances/{seanceId}/end-visio', [LMSVisioLifecycleController::class, 'endVisio'])
        ->name('lms.seances.end-visio')
        ->middleware('role:enseignant,coordinateur');

    Route::post('/seances/{seanceId}/recording/start', [LMSVisioRecordingController::class, 'start'])
        ->name('lms.seances.recording.start')
        ->middleware('role:enseignant');

    Route::post('/seances/{seanceId}/recording/stop', [LMSVisioRecordingController::class, 'stop'])
        ->name('lms.seances.recording.stop')
        ->middleware('role:enseignant');

    Route::get('/seances/{seanceId}/recording', [LMSVisioRecordingController::class, 'show'])
        ->name('lms.seances.recording.show');

    // Consentement visio du porteur — le raccord entre le recueil (front #333)
    // et le garde d'enregistrement (#716). Sans lui, `recording/start` refusait
    // TOUT enregistrement en 422, faute de ligne `consents` que rien n'ecrivait.
    //
    // Aucune garde de role : consentir a etre filme concerne tout participant,
    // enseignant comme etudiant. Le sujet est toujours le porteur du jeton.
    // Throttle : la table `consents` est APPEND-ONLY. Chaque depot y ajoute
    // trois lignes et n'en retire jamais. Sans borne, un client boucle et fait
    // grossir indefiniment une table dont on ne peut rien effacer.
    Route::post('/visio/consent', [LMSVisioConsentController::class, 'store'])
        ->name('lms.visio.consent.store')
        ->middleware('throttle:10,1');

    Route::get('/visio/consent', [LMSVisioConsentController::class, 'show'])
        ->name('lms.visio.consent.show');

    // Étudiant rejoint visio
    Route::post('/seances/{seanceId}/join', [LMSVisioParticipantController::class, 'joinVisio'])
        ->name('lms.seances.join')
        ->middleware('throttle:300,1');

    // Étudiant quitte visio
    Route::post('/seances/{seanceId}/leave', [LMSVisioParticipantController::class, 'leaveVisio'])
        ->name('lms.seances.leave')
        ->middleware('throttle:300,1');

    // Participation visio en cours de l'appelant (#673) : remonte la salle
    // embarquee apres un rechargement de page. Ne delivre AUCUN jeton.
    Route::get('/visio/active', [LMSVisioParticipantController::class, 'activeParticipation'])
        ->name('lms.visio.active')
        ->middleware('throttle:300,1');

    // Heartbeat participant (ping d'activité) - limité par utilisateur et séance
    Route::post('/seances/{seanceId}/heartbeat', [LMSVisioParticipantController::class, 'heartbeatVisio'])
        ->name('lms.seances.heartbeat')
        ->middleware('throttle:visio-heartbeat');

    // Liste des participants connectés à une visio.
    // REQ-4 du spec : route renommée `/visio-participants` pour résoudre un
    // conflit avec l'ancienne `lms.seances.participants`, retirée depuis (D610).
    // Réservé au personnel (GHSA-gg7j) : la liste porte nom, e-mail et heures de
    // connexion de toute la classe, lus dans la base LOCALE — aucune garde amont
    // ne la protège. Le bouton qui l'ouvre est déjà réservé côté front
    // (VisioManager.vue) ; la route l'est désormais aussi.
    Route::get('/seances/{seanceId}/visio-participants', [LMSVisioParticipantController::class, 'getVisioParticipants'])
        ->middleware('personnel')
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

    // Préférences de notification d'un utilisateur. Route PLATEFORME (D54) ; le
    // contrôleur garde la règle de #512 sur les préférences d'un autre compte.
    Route::get('/notifications/preferences/{userId}', [LMSNotificationsPreferencesController::class, 'getNotificationPreferences'])
        ->middleware('plateforme')
        ->name('lms.notifications.preferences');
});

Route::post('/webhooks/visio/recording-ready', [VisioRecordingWebhookController::class, 'recordingReady'])
    ->middleware('throttle:60,1')
    ->name('webhooks.visio.recording-ready');
