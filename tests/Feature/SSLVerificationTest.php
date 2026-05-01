<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Validation\SSLVerificationValidator;
use Illuminate\Foundation\Application;

/**
 * CRITICAL-08: SSL Verification Validation Tests
 *
 * Ensure SSL verification is enforced in production
 * and allowed to be disabled in development (for self-signed certs)
 */
class SSLVerificationTest extends TestCase
{
    /**
     * Test that production with ssl_verify=false throws exception
     */
    public function test_production_with_ssl_disabled_throws_exception()
    {
        $app = $this->createMock(Application::class);
        $app->method('isProduction')->willReturn(true);
        $app->method('environment')->willReturn('production');

        config(['services.klassci.ssl_verify' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SSL verification disabled in production/');

        SSLVerificationValidator::validate($app);
    }

    /**
     * Test that production with ssl_verify=true (default) is allowed
     */
    public function test_production_with_ssl_enabled_is_allowed()
    {
        $app = $this->createMock(Application::class);
        $app->method('isProduction')->willReturn(true);
        $app->method('environment')->willReturn('production');

        config(['services.klassci.ssl_verify' => true]);

        $this->expectNotToPerformAssertions();
        SSLVerificationValidator::validate($app);
    }

    /**
     * Test that development with ssl_verify=false is allowed (for local testing)
     */
    public function test_development_with_ssl_disabled_is_allowed()
    {
        $app = $this->createMock(Application::class);
        $app->method('isProduction')->willReturn(false);
        $app->method('environment')->willReturn('local');

        config(['services.klassci.ssl_verify' => false]);

        $this->expectNotToPerformAssertions();
        SSLVerificationValidator::validate($app);
    }

    /**
     * Test that development with ssl_verify=true (default) is allowed
     */
    public function test_development_with_ssl_enabled_is_allowed()
    {
        $app = $this->createMock(Application::class);
        $app->method('isProduction')->willReturn(false);
        $app->method('environment')->willReturn('local');

        config(['services.klassci.ssl_verify' => true]);

        $this->expectNotToPerformAssertions();
        SSLVerificationValidator::validate($app);
    }

    /**
     * Validate error message content for production failure
     */
    public function test_production_error_message_is_actionable()
    {
        $app = $this->createMock(Application::class);
        $app->method('isProduction')->willReturn(true);
        $app->method('environment')->willReturn('production');

        config(['services.klassci.ssl_verify' => false]);

        try {
            SSLVerificationValidator::validate($app);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('KLASSCI_SSL_VERIFY=true', $e->getMessage());
            $this->assertStringContainsString('cannot start', $e->getMessage());
            return;
        }

        $this->fail('Expected RuntimeException was not thrown');
    }
}
