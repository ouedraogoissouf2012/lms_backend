<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\FileConversion\FileValidator;
use App\Services\FileConversion\PdfToPngRenderer;
use App\Services\FileConversionService;
use Illuminate\Support\ServiceProvider;

/**
 * Wires up the file-conversion stack into the Laravel container.
 *
 * Singletons are used for helpers that are stateless or hold expensive
 * resolved values (binary lookups, optional logger). Each per-format
 * converter (`PowerPointConverter`, `WordConverter`, `PdfConverter`) is
 * resolved on demand because Laravel auto-injects their typed
 * dependencies — there is no benefit to caching them as singletons.
 *
 * `ShellExecutor` is intentionally NOT bound here : it lives in
 * `AppServiceProvider` because it is a generic infrastructure component
 * potentially reusable beyond file conversion.
 *
 * @see \App\Providers\AppServiceProvider For the ShellExecutor binding.
 */
final class FileConversionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FileValidator::class);
        $this->app->singleton(PdfToPngRenderer::class);

        // Facade — singleton because ChapterController holds it for the
        // request lifecycle and there is no per-call state to isolate.
        $this->app->singleton(FileConversionService::class);
    }
}
