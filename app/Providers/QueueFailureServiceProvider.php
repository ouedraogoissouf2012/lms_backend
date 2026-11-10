<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Logging\DescribedFailedJobProvider;
use App\Support\Logging\ExceptionDescription;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Le stockage des jobs échoués décrit l'exception (`DescribedFailedJobProvider`).
 *
 * `extend` s'applique au service `queue.failer` à sa résolution, qu'il vienne
 * d'un fournisseur différé (`QueueServiceProvider`) ou non.
 */
final class QueueFailureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(
            'queue.failer',
            static fn (FailedJobProviderInterface $failer, Application $app): DescribedFailedJobProvider => new DescribedFailedJobProvider(
                $failer,
                $app->make(ExceptionDescription::class),
            ),
        );
    }
}
