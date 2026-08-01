<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use App\Services\KlassciProxyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware EnsureKlassciSync
 *
 * S'assure que les données utilisateur KLASSCI sont à jour (< 24h).
 * Si les données sont obsolètes, déclenche une re-synchronisation automatique.
 *
 * ## Sécurité — invariants verrouillés ici (CRITICAL-05 / issue #34)
 *
 * Ce middleware s'exécute passivement à chaque requête authentifiée (via le
 * groupe `klassci.sync`). Si un attaquant compromet le serveur KLASSCI, il
 * peut faire renvoyer `auth/me` avec un payload arbitraire — y compris
 * `role: 'supradmin'` ou un email substitué.
 *
 * Pour qu'une telle compromission ne se traduise PAS par une escalade de
 * privilèges silencieuse côté LMS :
 *
 *  1. Le re-sync ne met JAMAIS à jour `users.role` (autorisation LMS).
 *  2. Le re-sync ne met JAMAIS à jour `users.email` (anti-hijack des
 *     password-reset).
 *  3. Si le rôle KLASSCI reçu diverge du rôle LMS courant, on émet un
 *     `Log::warning` détaillé pour détection a posteriori côté SOC, sans
 *     bloquer la requête (la divergence peut être légitime : admin LMS qui
 *     a promu un user — auquel cas `role > klassci_role` est attendu).
 *
 * Voir `.claude/specs/critical-05-klassci-role-separation/` pour la spec.
 */
class EnsureKlassciSync
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof User) {
            // Pas d'utilisateur authentifié — `auth:sanctum` se chargera de bloquer.
            return $next($request);
        }

        if ($user->isKlassciDataFresh()) {
            return $next($request);
        }

        Log::info("Re-synchronisation KLASSCI pour user {$user->id}", [
            'user_id'   => $user->id,
            'last_sync' => $user->last_klassci_sync?->toIso8601String(),
        ]);

        try {
            $klassciToken = $user->klassci_token;
            if (!is_string($klassciToken) || $klassciToken === '') {
                Log::warning("Re-synchronisation KLASSCI ignorée pour user {$user->id}", [
                    'user_id' => $user->id,
                    'reason' => 'missing_user_token',
                ]);

                return $next($request);
            }

            $klassciMe = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'auth/me',
                'GET',
            );

            if (isset($klassciMe['data']['user'])) {
                $klassciUser = $klassciMe['data']['user'];

                $this->detectAndLogRoleDivergence($user, $klassciUser);

                // SÉCURITÉ — champs EXCLUS volontairement du re-sync passif :
                //   • `role`                  (CRITICAL-05 #34 — autorisation LMS)
                //   • `email`                 (CRITICAL-05 #34 — anti-hijack password-reset)
                //   • `klassci_enseignant_id` (#119 — ownership évaluations, write-once
                //                              au sign-up via AuthController::syncUserFromKlassci)
                //
                // `klassci_data` continue à être écrasé en bloc — c'est un cache display
                // informationnel, plus aucun consommateur d'autorisation ne le lit
                // (vérifié grep post-#119). Le commentaire de garde dans User::klassci_data
                // documente cette règle pour les futurs développeurs.
                $user->update([
                    'name'              => $klassciUser['nom'] ?? $klassciUser['name'] ?? $user->name,
                    'klassci_role'      => $klassciUser['role'] ?? $user->klassci_role,
                    'klassci_data'      => json_encode($klassciUser),
                    'last_klassci_sync' => now(),
                ]);

                Log::info("Re-synchronisation KLASSCI réussie pour user {$user->id}");
            }
        } catch (\Exception $e) {
            // En cas d'erreur, logger mais continuer quand même.
            // Les données locales (même obsolètes) restent utilisables — `role`,
            // `email`, `klassci_role` sont tous préservés en l'état.
            Log::warning("Échec re-synchronisation KLASSCI pour user {$user->id}", [
                'error' => $e->getMessage(),
            ]);
        }

        return $next($request);
    }

    /**
     * Emit a structured warning when the KLASSCI-reported role diverges from
     * the LMS authorization role. Helps SOC/SIEM detect attempted silent
     * privilege escalation via a compromised KLASSCI server.
     *
     * No action is taken on the request — divergence can be legitimate (LMS
     * admin promoted a user, so `role > klassci_role` is expected). The flag
     * `is_escalation_attempt` filters out benign cases (KLASSCI < LMS) from
     * suspicious ones (KLASSCI > LMS).
     *
     * @param  array<string, mixed>  $klassciUser  Payload `data.user` from auth/me.
     */
    private function detectAndLogRoleDivergence(User $user, array $klassciUser): void
    {
        $incomingKlassciRole = $klassciUser['role'] ?? null;

        if (!is_string($incomingKlassciRole) || $incomingKlassciRole === $user->role) {
            return;
        }

        Log::warning('klassci_role_divergence_detected', [
            'user_id'               => $user->id,
            'institution_id'        => $user->institution_id,
            'lms_role'              => $user->role,
            'klassci_role_received' => $incomingKlassciRole,
            'klassci_role_previous' => $user->klassci_role,
            'is_escalation_attempt' => $this->isEscalationAttempt($user->role, $incomingKlassciRole),
        ]);
    }

    /**
     * Returns true iff `$klassciRole` is *more permissive* than `$lmsRole`
     * according to the hierarchy defined in `App\Enums\Role::permissivity()`
     * (issue #121 — source de vérité unique). Used only to qualify divergence
     * findings — never for authorization decisions.
     *
     * Unknown roles map to permissivity 0 (less than any known role).
     */
    private function isEscalationAttempt(?string $lmsRole, ?string $klassciRole): bool
    {
        $lmsLevel     = Role::tryFromString($lmsRole)?->permissivity() ?? 0;
        $klassciLevel = Role::tryFromString($klassciRole)?->permissivity() ?? 0;

        return $klassciLevel > $lmsLevel;
    }
}
