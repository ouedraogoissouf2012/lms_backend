<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La porte de l'API : un compte sans établissement n'atteint que les routes de
 * GESTION marquées « plateforme ».
 *
 * Elle enveloppe TOUTES les routes API (`routes/api.php`) et laisse passer :
 * - une requête sans utilisateur (l'authentification de la route, ou la route
 *   publique, décide ensuite) ;
 * - un membre d'une école ({@see User::isSchoolMember()}) ;
 * - un compte sans établissement, SEULEMENT sur une route qui porte le
 *   marqueur `plateforme` ({@see MarksPlatformRoute}).
 * Partout ailleurs : 403. Une route nouvelle naît donc fermée à un tel compte.
 *
 * ## Le compte, et non le tenant résolu
 *
 * La porte lit l'appartenance du COMPTE. En production, ResolveInstitution pose
 * le tenant pour tout porteur d'un compte d'école, ou refuse la requête si
 * l'école est inactive. Les deux sont donc équivalents en production.
 *
 * ## Sa place dans la pile
 *
 * Juste après l'authentification (`bootstrap/app.php`, liste de priorité) : une
 * requête sans jeton reçoit le 401 de `auth:sanctum`, et le refus vient avant
 * toute résolution de modèle par la route.
 */
final class EnsureSchoolMemberOrPlatformRoute
{
    public const MESSAGE = "Cet espace est réservé aux comptes d'un établissement.";

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isSchoolMember() || $this->isPlatformRoute($request)) {
            return $next($request);
        }

        return response()->json(['success' => false, 'message' => self::MESSAGE], Response::HTTP_FORBIDDEN);
    }

    private function isPlatformRoute(Request $request): bool
    {
        $route = $request->route();

        return $route !== null && in_array(MarksPlatformRoute::ALIAS, $route->gatherMiddleware(), true);
    }
}
