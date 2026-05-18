<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
    /**
     * Hiérarchie de permissivité interne — sert UNIQUEMENT à qualifier les
     * findings du log de divergence (`is_escalation_attempt`). N'est JAMAIS
     * utilisée pour de l'autorisation : l'autorisation passe par `EnsureRole`
     * et `User::isAdmin()`, sources de vérité.
     *
     * @var array<string, int>
     */
    private const ROLE_PERMISSIVITY = [
        'etudiant'       => 1,
        'student'        => 1,
        'enseignant'     => 2,
        'teacher'        => 2,
        'coordinateur'   => 3,
        'coordinator'    => 3,
        'admin'          => 4,
        'administrateur' => 4,
        'superAdmin'     => 5,
        'supradmin'      => 5,
    ];

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
            $klassciMe = $this->klassciService->get('auth/me');

            if (isset($klassciMe['data']['user'])) {
                $klassciUser = $klassciMe['data']['user'];

                $this->detectAndLogRoleDivergence($user, $klassciUser);

                // SÉCURITÉ — REQ-4 de la spec critical-05 :
                // Le re-sync passif ne doit PAS pouvoir écraser :
                //   • `role`  (autorisation LMS — source de vérité unique)
                //   • `email` (peut servir à des checks d'autorité futurs +
                //              vecteur d'interception de password-reset)
                // Seuls les champs informatifs sont propagés.
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
     * according to the internal hierarchy. Used only to qualify divergence
     * findings — never for authorization decisions.
     */
    private function isEscalationAttempt(?string $lmsRole, ?string $klassciRole): bool
    {
        $lmsLevel     = self::ROLE_PERMISSIVITY[$lmsRole] ?? 0;
        $klassciLevel = self::ROLE_PERMISSIVITY[$klassciRole] ?? 0;

        return $klassciLevel > $lmsLevel;
    }
}
