<?php

namespace App\Providers;

use App\Validation\SSLVerificationValidator;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * CRITICAL-08: SSL Verification Service Provider
 *
 * Registers SSL verification validation to run during application boot.
 * This ensures SSL is properly configured before any HTTP requests are handled.
 */
class SSLVerificationProvider extends ServiceProvider
{
    /**
     * Register SSL verification validation
     */
    public function register(): void
    {
        // Validate SSL configuration during boot phase
        $this->app->booting(function () {
            if ($this->app instanceof Application) {
                SSLVerificationValidator::validate($this->app);
            }
        });
    }
}
