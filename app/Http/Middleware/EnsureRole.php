<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
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

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Non authentifié',
            ], 401);
        }

        // Les rôles de la route, en Role (#949).
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
     * Les rôles de la route, lus comme ceux des comptes : `Role::tryFromString`,
     * correspondance exacte (#949). Un paramètre inconnu n'admet personne.
     *
     * @param  array<array-key, string>  $roles  les paramètres variadiques de la route
     * @return list<Role>
     */
    private function normalizeRoles(array $roles): array
    {
        $admis = [];
        foreach ($roles as $role) {
            $enum = Role::tryFromString($role);
            if ($enum !== null && ! in_array($enum, $admis, true)) {
                $admis[] = $enum;
            }
        }

        return $admis;
    }

    /**
     * Le compte a-t-il l'un des rôles de la route ? Une seule lecture du rôle
     * (#949) : celle des services. Une variante de casse ou d'espace n'est aucun
     * rôle, ici comme ailleurs.
     *
     * @param  list<Role>  $allowedRoles
     */
    private function userHasRole(User $user, array $allowedRoles): bool
    {
        // Gestionnaire PLATEFORME (cross-tenant) : bypass total, comparaison
        // STRICTE (#511, `isPlatformSupradmin()`).
        if ($user->isPlatformSupradmin()) {
            return true;
        }

        $role = Role::tryFromString($user->role);

        // superAdmin (admin institution) passe tous les contrôles SAUF les routes
        // réservées au gestionnaire plateforme.
        if ($role === Role::SuperAdmin && ! in_array(Role::Supradmin, $allowedRoles, true)) {
            return true;
        }

        // Le rôle plateforme n'est satisfait que par un vrai supradmin, traité
        // ci-dessus (#511).
        return $role !== null && $role !== Role::Supradmin && in_array($role, $allowedRoles, true);
    }
}
