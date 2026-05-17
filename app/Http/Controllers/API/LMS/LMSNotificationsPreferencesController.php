<?php

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\SendSessionReminderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * LMS Notifications Preferences — préférences de notification utilisateur + envoi rappels séance.
 *
 * Extracted from `LMSDataController` as part of the god-object refactor
 * (spec: `.claude/specs/lms-data-controller-split/`).
 *
 * Responsibilities:
 *   - GET  /api/lms/notifications/preferences/{userId}     → getNotificationPreferences()
 *   - POST /api/lms/notifications/send-session-reminder    → sendSessionReminder()
 *
 * Note: both methods are currently placeholders (TODO markers in body).
 *   - `getNotificationPreferences` returns hardcoded defaults pending the
 *     `parent_notification_preferences` table integration.
 *   - `sendSessionReminder` returns success but doesn't yet integrate with
 *     `NotificationService`.
 * These TODOs are inherited verbatim from the legacy code. They are out of
 * scope for this refactor; tracking ticket should be opened separately for
 * the wiring work.
 *
 * Security note inherited from the legacy: `getNotificationPreferences` checks
 * `$currentUser->id === $userId || role IN ('coordinateur', 'superAdmin')`. The
 * role-based bypass does NOT verify cross-tenant — a `superAdmin` of institution
 * A could currently read preferences of a user of institution B. Same bug pattern
 * as #87/#91/#98/#102/#103. Follow-up security ticket to file.
 */
final class LMSNotificationsPreferencesController extends AuthenticatedController
{
    /**
     * GET /api/lms/notifications/preferences/{userId}
     * Retourne les préférences de notification pour un utilisateur.
     *
     * Authorization:
     *   - The user can read their own preferences.
     *   - `coordinateur` and `superAdmin` can read any user's preferences
     *     (intra-tenant currently, cross-tenant gap documented above).
     *
     * Body is currently a stub returning hardcoded defaults — pending the
     * `parent_notification_preferences` table integration.
     */
    public function getNotificationPreferences(int $userId, Request $request): JsonResponse
    {
        try {
            $currentUser = $this->authenticatedUser($request);

            if ($currentUser->id !== $userId && !in_array($currentUser->role, ['coordinateur', 'superAdmin'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé'
                ], 403);
            }

            // TODO: Récupérer depuis parent_notification_preferences

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $userId,
                    'channels' => [
                        'whatsapp' => true,
                        'email' => true,
                        'sms' => false,
                        'app' => true
                    ],
                    'preferences' => [
                        'session_reminder_minutes' => 15,
                        'evaluation_reminder_hours' => 24,
                        'absence_notification' => true
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération préférences notifications', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des préférences',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/notifications/send-session-reminder
     * Envoie un rappel pour une séance.
     *
     * Currently a stub — returns success but doesn't yet wire to the
     * `NotificationService` integration. TODO inherited from legacy.
     */
    public function sendSessionReminder(SendSessionReminderRequest $request): JsonResponse
    {
        try {
            // Authentication is enforced upstream by `auth:sanctum` and
            // the FormRequest validates the payload. We still call
            // authenticatedUser() to enforce the contract for downstream
            // (auditing/tracing) consistency.
            $this->authenticatedUser($request);

            $seanceCoursId = $request->validated('seance_cours_id');
            $channels = $request->validated('channels');
            $minutesBefore = $request->validated('minutes_before') ?? 15;

            Log::info('Envoi rappel séance', [
                'seance_cours_id' => $seanceCoursId,
                'channels' => $channels,
                'minutes_before' => $minutesBefore
            ]);

            // TODO: Intégrer avec NotificationService existant

            return response()->json([
                'success' => true,
                'message' => 'Rappels envoyés avec succès',
                'data' => [
                    'seance_cours_id' => $seanceCoursId,
                    'channels' => $channels,
                    'sent_count' => 0,
                    'note' => 'TODO: Intégration NotificationService à compléter'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur envoi rappel séance', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi des rappels',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }
}
