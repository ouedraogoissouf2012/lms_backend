<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Versioning par path (#217). Le montage `api:` ci-dessus sert le path
        // NON-VERSIONNÉ `/api/...` (conservé pour rétrocompat — pas de 301 qui
        // casserait le frontend en prod). On ajoute ici les alias versionnés :
        //   - `/api/v1/...` : MÊMES routes (noms préfixés `v1.` pour éviter les
        //     collisions avec le montage non-versionné).
        //   - `/api/v2/...` : espace réservé aux futurs breaking changes.
        // Cf. docs/API_VERSIONING.md.
        then: function (): void {
            \Illuminate\Support\Facades\Route::middleware('api')
                ->prefix('api/v1')
                ->name('v1.')
                ->group(base_path('routes/api.php'));

            \Illuminate\Support\Facades\Route::middleware('api')
                ->prefix('api/v2')
                ->name('v2.')
                ->group(base_path('routes/v2.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Middleware global API : résolution de l'institution (multi-tenant)
        $middleware->api(prepend: [
            \App\Http\Middleware\ResolveInstitution::class,
        ]);

        // Enregistrer les middlewares personnalisés
        $middleware->alias([
            'klassci.sync' => \App\Http\Middleware\EnsureKlassciSync::class,
            'role' => \App\Http\Middleware\EnsureRole::class,
            'institution' => \App\Http\Middleware\ResolveInstitution::class,
        ]);

        // #244 : API stateless — ne JAMAIS rediriger un invité vers une page de
        // login web (`route('login')` n'existe pas → 500 RouteNotFoundException
        // quand la requête n'a pas `Accept: application/json`). En retournant
        // null, le middleware Authenticate laisse remonter l'AuthenticationException
        // qui est rendue en 401 JSON (cf. withExceptions ci-dessous).
        $middleware->redirectGuestsTo(fn () => null);
    })
    // OPS-03 — les schedules vivent dans `routes/console.php` (convention Laravel 11).
    // Avant cette PR, 2 commandes étaient déclarées en double ici ET dans routes/console.php :
    //   • `evaluations:notify-upcoming` (dailyAt 08:00) → exécutée 2× chaque matin en prod
    //   • `visio:detect-inactive` (everyMinute) faisait LA MÊME chose que le Job
    //     `DetectDisconnectedParticipants` (every2Minutes) — double scan ESBTPAttendance
    // Source unique conservée : `routes/console.php`.
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            // #244 : l'API est stateless. Toute route `api/*` (versionnée ou non)
            // doit répondre 401 JSON, même si la requête n'envoie pas
            // `Accept: application/json` (ex. Swagger mal configuré, navigateur).
            // Sans ce `is('api/*')`, Laravel tenterait une redirection web vers
            // `route('login')` — inexistante en API → 500 `Route [login] not defined`.
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        // Handle ValidationException for FormRequests
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        // Rate limiting (#214) : le 429 DOIT porter `Retry-After` + les en-têtes
        // `X-RateLimit-*` (RFC 6585) pour que le client sache quand réessayer.
        // Sans ce handler dédié, le handler générique \Throwable ci-dessous
        // reconstruirait la réponse JSON en PERDANT les headers de l'exception.
        $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Trop de requêtes. Veuillez réessayer plus tard.',
                ], 429, $e->getHeaders());
            }
        });

        // #270 — KLASSCI indisponible (URL de base absente/invalide, ou service
        // injoignable) = panne EXTERNE temporaire → 503 (retryable), jamais 500.
        // Handler canonique : couvre tout appelant qui laisse remonter l'exception
        // (les contrôleurs proxy, eux, la traduisent localement via le trait
        // RendersKlassciProxyErrors car ils enveloppent chaque appel dans un catch).
        // Doit précéder le handler \Throwable générique ci-dessous, sinon ce dernier
        // la rendrait en 500. On n'expose JAMAIS le détail technique (§1.2).
        $exceptions->render(function (\App\Exceptions\KlassciUnavailableException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => \App\Exceptions\KlassciUnavailableException::CLIENT_MESSAGE,
                ], 503);
            }
        });

        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->expectsJson()) {
                $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
                // CRITICAL-02: ne pas se fier à `isProduction()` seul (laisserait fuir
                // les détails en staging/demo/preview). On gate sur `config('app.debug')`
                // qui est le contrat Laravel standard pour exposer les détails.
                $message = config('app.debug') ? $e->getMessage() : 'Une erreur est survenue.';

                \Illuminate\Support\Facades\Log::error('Exception non-catchée', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'url' => $request->fullUrl(),
                    'method' => $request->getMethod(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json(['success' => false, 'message' => $message], $statusCode);
            }
        });
    })->create();

return $app;
