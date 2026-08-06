<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

/**
 * Middleware EnsureRole
 *
 * Vérifie que l'utilisateur authentifié possède l'un des rôles autorisés
 * Usage: ->middleware('role:enseignant,coordinateur')
 */
class EnsureRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles Liste des rôles autorisés
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // Vérifier si l'utilisateur est authentifié
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Non authentifié',
            ], 401);
        }

        // Normaliser les rôles autorisés (supporter les variantes FR/EN)
        $allowedRoles = $this->normalizeRoles($roles);

        // Vérifier si l'utilisateur a l'un des rôles autorisés
        if (!$this->userHasRole($user, $allowedRoles)) {
            Log::warning("Accès refusé - Rôle insuffisant", [
                'user_id' => $user->id,
                'user_role' => $user->role,
                'required_roles' => $roles,
                'endpoint' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Accès refusé - Permissions insuffisantes',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Normalise les rôles (supporter variantes FR/EN)
     *
     * @param array $roles
     * @return array
     */
    private function normalizeRoles(array $roles): array
    {
        $normalized = [];

        $roleMapping = [
            'enseignant'   => ['enseignant', 'teacher'],
            'etudiant'     => ['etudiant', 'student'],
            'coordinateur' => ['coordinateur', 'coordinator'],
            'admin'        => ['admin', 'administrateur', 'administrator'],
            'superadmin'   => ['superadmin'],  // FIX #27 & #28: Unified lowercase
            'supradmin'    => ['supradmin'],
        ];

        foreach ($roles as $role) {
            $roleLower = strtolower(trim($role));

            // Trouver toutes les variantes du rôle
            foreach ($roleMapping as $variants) {
                if (in_array($roleLower, $variants)) {
                    $normalized = array_merge($normalized, $variants);
                }
            }

            // Si rôle non mappé, l'ajouter quand même
            if (!in_array($roleLower, $normalized)) {
                $normalized[] = $roleLower;
            }
        }

        return array_unique($normalized);
    }

    /**
     * Vérifie si l'utilisateur a l'un des rôles autorisés
     *
     * @param \App\Models\User $user
     * @param array $allowedRoles
     * @return bool
     */
    private function userHasRole($user, array $allowedRoles): bool
    {
        // Gestionnaire PLATEFORME (cross-tenant) : bypass total, mais comparaison
        // STRICTE alignée sur isPlatformSupradmin() (#511) — une variante de casse
        // ('Supradmin', 'SUPRADMIN') ne doit JAMAIS franchir cette barrière
        // cross-tenant, cohérent avec les gardes de lecture (FileQueryService…).
        if ($user->isPlatformSupradmin()) {
            return true;
        }

        $userRole = strtolower(trim($user->role));

        // superAdmin (admin institution) bypasse tous les contrôles SAUF les routes
        // réservées au gestionnaire plateforme.
        if ($userRole === 'superadmin' && !in_array('supradmin', $allowedRoles, true)) {
            return true;
        }

        // Match nominal insensible à la casse. Le rôle plateforme `supradmin` est
        // EXCLU de ce match : il n'est satisfait que par un vrai platform supradmin
        // (traité ci-dessus), jamais par une variante de casse (#511).
        $matchableRoles = array_diff($allowedRoles, ['supradmin']);

        return in_array($userRole, $matchableRoles, true);
    }
}
