<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le marqueur d'une route de GESTION plateforme : `->middleware('plateforme')`.
 *
 * Il ne fait rien lui-même. Sa présence sur la route est ce que lit la porte
 * ({@see EnsureSchoolMemberOrPlatformRoute}) pour laisser passer un compte sans
 * établissement. La liste des routes marquées est figée par un test
 * (`tests/Feature/Security/PlatformRoutesListTest.php`) : en ajouter une est un
 * choix conscient, relu.
 */
final class MarksPlatformRoute
{
    public const ALIAS = 'plateforme';

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
