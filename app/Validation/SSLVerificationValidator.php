<?php

namespace App\Validation;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;

/**
 * CRITICAL-08: SSL Verification Validator
 *
 * Enforces SSL certificate verification in production environments.
 * Fails-secure: application cannot start if SSL is disabled in production.
 *
 * Why: Disabling SSL verification enables MITM attacks. Production must
 * always verify SSL certificates. This validator prevents accidental
 * security degradation through configuration drift.
 */
class SSLVerificationValidator
{
    /**
     * Validate SSL configuration on application start
     *
     * @param Application $app
     * @throws \RuntimeException if SSL disabled in production
     */
    public static function validate(Application $app): void
    {
        $sslVerifyEnabled = config('services.klassci.ssl_verify', true);
        $isProduction = $app->isProduction();

        // Fail-secure: production must always verify SSL
        if ($isProduction && !$sslVerifyEnabled) {
            $message = 'CRITICAL SECURITY ERROR: SSL verification disabled in production. ' .
                      'Set KLASSCI_SSL_VERIFY=true in .env or remove the setting (default: true). ' .
                      'Application cannot start with this configuration.';

            Log::critical($message, [
                'env' => app()->environment(),
                'ssl_verify' => $sslVerifyEnabled,
            ]);

            throw new \RuntimeException($message);
        }

        // Log warning in development if SSL disabled (informational only)
        if (!$isProduction && !$sslVerifyEnabled) {
            Log::warning('SSL verification disabled in development environment', [
                'env' => app()->environment(),
                'note' => 'This is acceptable for local development with self-signed certificates',
            ]);
        }
    }
}
