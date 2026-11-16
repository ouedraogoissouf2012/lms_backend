<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Une garde de route qui délègue à UN prédicat de rôle déjà écrit sur `User` (D499/D502),
 * au lieu d'une liste `role:` recopiée groupe par groupe.
 *
 * Avant, chaque groupe de routes recopiait sa liste, chacun à sa façon : `admin` (rang 4,
 * au-dessus du coordinateur) recevait 403 là où le coordinateur passait. Les prédicats
 * `isManager()` et `isStaff()` (`InteractsWithRoles`) disent déjà qui gère et qui est du
 * personnel ; les gardes les lisent, et ne les recopient plus.
 *
 * Les rôles sont lus par `Role::tryFromString`, en correspondance EXACTE (#511, #941) :
 * une variante de casse d'un rôle (`Coordinateur`, `SuperAdmin`) n'y gagne aucun droit,
 * alors qu'`EnsureRole` comparait en minuscules. Le supradmin plateforme est arrêté avant,
 * par `EnsureSchoolMemberOrPlatformRoute` : ces gardes ne servent que les routes d'une école.
 *
 * Les réponses 401 et 403 ont la forme de celles d'`EnsureRole`.
 */
abstract class EnsurePredicatDeRole
{
    /** Le prédicat de rôle que la garde applique. */
    abstract protected function admet(User $user): bool;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Non authentifié',
            ], 401);
        }

        if (! $this->admet($user)) {
            Log::warning('Accès refusé - Rôle insuffisant', [
                'user_id' => $user->id,
                'user_role' => $user->role,
                'garde' => static::class,
                'endpoint' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Accès refusé - Permissions insuffisantes',
            ], 403);
        }

        return $next($request);
    }
}
