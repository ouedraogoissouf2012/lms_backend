<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
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
    ->withSchedule(function ($schedule): void {
        // Détecter les participants inactifs toutes les minutes
        $schedule->command('visio:detect-inactive')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Notifier les étudiants des évaluations approchantes (24h avant)
        $schedule->command('evaluations:notify-upcoming --hours=24')
            ->dailyAt('08:00')
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle authentication failures
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        // Convert Laravel's ModelNotFoundException to ResourceNotFoundException
        $exceptions->render(function (Illuminate\Database\Eloquent\ModelNotFoundException $e, $request) {
            if ($request->expectsJson()) {
                $apiException = new App\Exceptions\ResourceNotFoundException(
                    'Ressource non trouvée',
                    null,
                    404,
                    ['model' => get_class($e->getModel())]
                );
                return $apiException->render();
            }
        });

        // Handle custom API exceptions
        $exceptions->render(function (App\Exceptions\ApiException $e, $request) {
            if ($request->expectsJson()) {
                // Log with full context
                \Illuminate\Support\Facades\Log::error('API Exception: ' . class_basename($e), [
                    'exception_class' => get_class($e),
                    'error_code' => $e->getErrorCode(),
                    'status' => $e->getStatusCode(),
                    'context' => $e->getContext(),
                    'trace' => $e->getTraceAsString(),
                    'user_id' => auth()->id(),
                    'ip' => $request->ip(),
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'query_params' => $request->query(),
                ]);

                return $e->render();
            }
        });

        // Handle uncaught exceptions generically
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->expectsJson()) {
                // Log any uncaught exception with full context
                \Illuminate\Support\Facades\Log::error('Uncaught Exception: ' . class_basename($e), [
                    'exception_class' => get_class($e),
                    'trace' => $e->getTraceAsString(),
                    'user_id' => auth()->id(),
                    'ip' => $request->ip(),
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'query_params' => $request->query(),
                ]);

                // Return generic error to client
                return response()->json([
                    'success' => false,
                    'error_code' => 'INTERNAL_SERVER_ERROR',
                    'message' => 'Erreur interne du serveur',
                ], 500);
            }
        });
    })->create();
