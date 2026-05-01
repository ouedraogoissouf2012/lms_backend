<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Validation\SSLVerificationValidator;

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
            SSLVerificationValidator::validate($this->app);
        });
    }
}
