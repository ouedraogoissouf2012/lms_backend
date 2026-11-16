<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le marqueur d'une route ouverte à TOUT compte authentifié :
 * `->middleware('tout.compte')` (#972).
 *
 * Il ne fait rien lui-même. Sa présence est ce que lit la porte
 * ({@see EnsureSchoolMemberOrPlatformRoute}) pour laisser passer un compte sans
 * établissement qui n'est pas le supradmin : lire son propre compte (l'écran
 * d'erreur en a besoin) et se déconnecter (révoquer son propre jeton ne lit et
 * n'expose rien). La liste est figée par un test
 * (`tests/Feature/Security/PorteDesComptesSansEtablissementTest.php`).
 */
final class MarksAnyAccountRoute
{
    public const ALIAS = 'tout.compte';

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
