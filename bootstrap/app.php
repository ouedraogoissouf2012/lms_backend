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
        // Enregistrer les middlewares personnalisés
        $middleware->alias([
            'klassci.sync' => \App\Http\Middleware\EnsureKlassciSync::class,
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);
    })
    ->withSchedule(function ($schedule): void {
        // Détecter les participants inactifs toutes les minutes
        $schedule->command('visio:detect-inactive')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
