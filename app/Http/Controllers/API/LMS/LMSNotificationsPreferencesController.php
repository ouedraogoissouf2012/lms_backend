<?php

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\SendSessionReminderRequest;
use App\Models\User;
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
 * Note : les deux méthodes sont des STUBS documentés. Le contrat de réponse est respecté,
 * mais le wiring backend reste à faire :
 *   - `getNotificationPreferences` retourne des defaults hardcodés en attendant l'intégration
 *     de la table `parent_notification_preferences`.
 *   - `sendSessionReminder` accepte la requête et renvoie success, mais n'envoie rien
 *     en attendant l'intégration avec `NotificationService`.
 * Cette docblock est la trace officielle du stub status (cf. PR #151). À traiter
 * dans une issue follow-up dédiée.
 *
 * Security (#512, corrige le pattern #87/#91/#98/#102/#103) : le bypass rôle de
 * `getNotificationPreferences` est désormais scopé au TENANT — un manager
 * (coordinateur/admin, incl. `superAdmin` d'institution) n'accède qu'aux users de
 * SON institution ; seul le gestionnaire PLATEFORME (`isPlatformSupradmin`) accède
 * en cross-tenant. Cf. {@see self::canAccessUserPreferences()}.
 */
final class LMSNotificationsPreferencesController extends AuthenticatedController
{
    /**
     * GET /api/lms/notifications/preferences/{userId}
     * Retourne les préférences de notification pour un utilisateur.
     *
     * Authorization:
     *   - The user can read their own preferences.
     *   - A manager (`coordinateur`/`superAdmin`) can read a user's preferences
     *     ONLY within their own institution (#512).
     *   - A platform `supradmin` can read cross-tenant.
     *
     * Body is currently a stub returning hardcoded defaults — pending the
     * `parent_notification_preferences` table integration.
     */
    public function getNotificationPreferences(int $userId, Request $request): JsonResponse
    {
        try {
            $currentUser = $this->authenticatedUser($request);

            if ($currentUser->id !== $userId && ! $this->canAccessUserPreferences($currentUser, $userId)) {
                return $this->errorResponse('Accès refusé', 403);
            }

            // Stub : retourne les defaults par contrat. L'intégration avec
            // `parent_notification_preferences` est suivie en follow-up séparé.
            return $this->successResponse([
                'user_id' => $userId,
                'channels' => [
                    'whatsapp' => true,
                    'email'    => true,
                    'sms'      => false,
                    'app'      => true,
                ],
                'preferences' => [
                    'session_reminder_minutes'  => 15,
                    'evaluation_reminder_hours' => 24,
                    'absence_notification'      => true,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération préférences notifications', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            // NON migré : la clé `error` (singulier) n'est pas reproductible par
            // `errorResponse` (qui n'expose qu'`errors` pluriel). Laisser intact
            // pour préserver le JSON client (axe #1 « DRY-only »).
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des préférences',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * Un manager (coordinateur/admin) n'accède qu'aux préférences d'un utilisateur
     * de SON institution ; le cross-tenant est réservé au gestionnaire PLATEFORME
     * (#512 — ferme l'IDOR : le bypass rôle ne vérifiait pas le tenant).
     *
     * DETTE TRACÉE : au dé-stub de l'endpoint (câblage `parent_notification_preferences`),
     * déplacer cette règle dans `GetNotificationPreferencesRequest::authorize()` via un
     * trait frère `ChecksUserAuthorization`, aligné sur `ChecksFileAuthorization` /
     * `ChecksEvaluationOwnership`, pour factoriser l'idiome cross-tenant.
     */
    private function canAccessUserPreferences(User $currentUser, int $userId): bool
    {
        // Gestionnaire plateforme : accès cross-tenant assumé.
        if ($currentUser->isPlatformSupradmin()) {
            return true;
        }

        if (! $currentUser->isManager()) {
            return false;
        }

        // Manager : uniquement un utilisateur de la MÊME institution. La cible est
        // chargée hors global scope pour comparer explicitement le tenant (la route
        // /lms/... ne résout pas nécessairement l'institution courante).
        $target = User::withoutGlobalScope('institution')->find($userId);

        // Invariant : un manager SANS institution n'a aucun accès délégué (évite
        // qu'un null === null n'accorde l'accès entre comptes tenant-less).
        return $currentUser->institution_id !== null
            && $target !== null
            && $target->institution_id === $currentUser->institution_id;
    }

    /**
     * POST /api/lms/notifications/send-session-reminder
     * Envoie un rappel pour une séance.
     *
     * Currently a stub — returns success but doesn't yet wire to the
     * `NotificationService` integration. Tracked in follow-up issue.
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

            // Stub : valide la requête et retourne success sans envoyer réellement.
            // Intégration avec `NotificationService` suivie en follow-up séparé.
            // Le `sent_count = 0` est intentionnellement honnête (rien n'a été envoyé).
            return $this->successResponse(
                [
                    'seance_cours_id' => $seanceCoursId,
                    'channels'        => $channels,
                    'sent_count'      => 0,
                ],
                'Rappels acceptés (intégration NotificationService en cours).',
            );

        } catch (\Exception $e) {
            Log::error('Erreur envoi rappel séance', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // NON migré : la clé `error` (singulier) n'est pas reproductible par
            // `errorResponse` (qui n'expose qu'`errors` pluriel). Laisser intact
            // pour préserver le JSON client (axe #1 « DRY-only »).
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi des rappels',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }
}
