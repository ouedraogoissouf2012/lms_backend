<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * La porte de l'API : un compte sans établissement n'atteint que les routes de
 * GESTION marquées « plateforme ».
 *
 * Elle enveloppe TOUTES les routes API (`routes/api.php`) et laisse passer :
 * - une requête sans utilisateur (l'authentification de la route, ou la route
 *   publique, décide ensuite) ;
 * - un membre d'une école ({@see User::isSchoolMember()}) ;
 * - tout compte, sur une route marquée `tout.compte` ({@see MarksAnyAccountRoute}) :
 *   lire son compte, se déconnecter ;
 * - le supradmin plateforme ({@see User::isPlatformSupradmin()}), SEULEMENT sur
 *   une route qui porte le marqueur `plateforme` ({@see MarksPlatformRoute}).
 *   Un autre compte sans établissement n'y passe pas (#972).
 * Partout ailleurs : 403, avec le motif `no_institution` (ADR-906-01), que le
 * front traduit par son catalogue. Une route nouvelle naît donc fermée à un tel
 * compte. Un utilisateur authentifié qui n'est pas un `User` n'a pas
 * d'établissement : il est refusé de même.
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

    /** Le motif du refus « compte sans établissement » (ADR-906-01). */
    public const REASON = 'no_institution';

    public function handle(Request $request, Closure $next): Response
    {
        // Le résolveur, et non `$request->user()` : celui-ci est déclaré `User|null`,
        // alors qu'un autre garde peut authentifier un autre `Authenticatable`.
        $user = ($request->getUserResolver())();

        if ($user === null || ($user instanceof User && $user->isSchoolMember()) || $this->porte($request, MarksAnyAccountRoute::ALIAS)) {
            return $next($request);
        }

        // Une route `plateforme` est au supradmin plateforme, et non à tout compte
        // sans établissement (#972) : un coordinateur ou un admin d'école à
        // `institution_id` nul y atteignait, sans tenant, les compteurs et l'envoi
        // de notifications.
        if ($user instanceof User && $user->isPlatformSupradmin() && $this->porte($request, MarksPlatformRoute::ALIAS)) {
            return $next($request);
        }

        return response()->json(
            ['success' => false, 'message' => self::MESSAGE, 'reason' => self::REASON],
            Response::HTTP_FORBIDDEN,
        );
    }

    /** La route porte-t-elle ce marqueur (`plateforme`, `tout.compte`) ? */
    private function porte(Request $request, string $marqueur): bool
    {
        $route = $request->route();

        return $route instanceof Route && in_array($marqueur, $route->gatherMiddleware(), true);
    }
}
