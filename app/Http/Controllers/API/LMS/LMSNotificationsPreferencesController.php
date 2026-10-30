<?php

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * LMS Notifications Preferences — préférences de notification utilisateur.
 *
 * Extracted from `LMSDataController` as part of the god-object refactor
 * (spec: `.claude/specs/lms-data-controller-split/`).
 *
 * Responsibilities:
 *   - GET  /api/lms/notifications/preferences/{userId}     → getNotificationPreferences()
 *
 * Note : `getNotificationPreferences` est un STUB documenté. Le contrat de réponse est
 * respecté, mais il retourne des defaults hardcodés en attendant l'intégration de la
 * table `parent_notification_preferences` (cf. PR #151).
 *
 * L'envoi de rappel de séance (`POST /lms/notifications/send-session-reminder`) a été
 * RETIRÉ : bouchon depuis sa naissance (ac614e1b), sans appelant, sans issue de suivi,
 * il répondait « Rappels acceptés » sans rien envoyer. Le besoin réel — prévenir les
 * élèves d'une séance — est servi par `DispatchVisioNotification` (activation et
 * bascule de la visio).
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
}
