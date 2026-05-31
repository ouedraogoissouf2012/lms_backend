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
    })
    // OPS-03 — les schedules vivent dans `routes/console.php` (convention Laravel 11).
    // Avant cette PR, 2 commandes étaient déclarées en double ici ET dans routes/console.php :
    //   • `evaluations:notify-upcoming` (dailyAt 08:00) → exécutée 2× chaque matin en prod
    //   • `visio:detect-inactive` (everyMinute) faisait LA MÊME chose que le Job
    //     `DetectDisconnectedParticipants` (every2Minutes) — double scan ESBTPAttendance
    // Source unique conservée : `routes/console.php`.
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
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
