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
                'required_roles' => $roles,
                'your_role' => $user->role,
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
            'enseignant' => ['enseignant', 'teacher'],
            'etudiant' => ['etudiant', 'student'],
            'coordinateur' => ['coordinateur', 'coordinator'],
            'admin' => ['admin', 'administrateur', 'administrator'],
            'supradmin' => ['supradmin'],
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
        $userRole = strtolower(trim($user->role));

        // Admin et superAdmin ont toujours accès à tout
        if (in_array($userRole, ['admin', 'administrateur', 'administrator', 'superadmin', 'supradmin'])) {
            return true;
        }

        // Vérifier si le rôle user est dans la liste des rôles autorisés
        return in_array($userRole, $allowedRoles);
    }
}
