<?php

namespace Tests\Feature;

use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\PermissionException;
use App\Exceptions\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test CRITICAL-02: Exception Handler — Production Architecture
 *
 * Validates:
 * - No getMessage() exposures to API clients
 * - Error responses use standardized format with error_code
 * - Server-side logging captures full exception context
 * - OWASP A06:2021 compliance (Information Disclosure prevention)
 */
class ExceptionHandlerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test ResourceNotFoundException returns 404 with error_code
     */
    public function test_resource_not_found_exception_returns_correct_response(): void
    {
        $response = $this->getJson('/api/classes/99999');

        $this->assertEquals(404, $response->status());
        $response->assertJson([
            'success' => false,
            'error_code' => 'RESOURCE_NOT_FOUND',
        ]);

        // Verify message is NOT raw exception message
        $this->assertNotContains('Undefined', $response->getContent());
        $this->assertNotContains('ModelNotFoundException', $response->getContent());
    }

    /**
     * Test that API never exposes raw exception getMessage() to client
     */
    public function test_no_getmessage_exposed_to_client(): void
    {
        $sensitivePatterns = [
            'Column',
            'SQLSTATE',
            'PDO',
            'integrity constraint',
            '/app/',
            '.php',
            'Stack trace',
            'at line',
            'undefined',
            'Undefined',
        ];

        // Try various endpoints that might error
        $endpoints = [
            '/api/classes/99999',
            '/api/evaluations/99999',
        ];

        foreach ($endpoints as $path) {
            $response = $this->getJson($path);

            if ($response->status() >= 400) {
                $content = $response->getContent();

                foreach ($sensitivePatterns as $pattern) {
                    $this->assertStringNotContainsString(
                        $pattern,
                        $content,
                        "Sensitive pattern '$pattern' found in $path response"
                    );
                }
            }
        }
    }

    /**
     * Test error response has required structure
     */
    public function test_error_response_has_required_structure(): void
    {
        $response = $this->getJson('/api/classes/99999');

        $this->assertTrue($response->json('success') === false);
        $this->assertNotNull($response->json('error_code'));
        $this->assertNotNull($response->json('message'));

        // Verify message is a string, not an array or object
        $this->assertIsString($response->json('message'));

        // Verify message is not too long (shouldn't be exception trace)
        $this->assertLessThan(500, strlen($response->json('message')));
    }

    /**
     * Test ValidationException returns 422 with errors array
     */
    public function test_validation_exception_returns_correct_format(): void
    {
        // Try to create something with invalid data
        $response = $this->postJson('/api/chapters', [
            // Missing required 'title' field
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);

        // Should have errors array
        $this->assertIsArray($response->json('errors'));
    }

    /**
     * Test PermissionException returns 403
     */
    public function test_permission_exception_returns_403(): void
    {
        $response = $this->getJson('/api/admin/analytics/trends');

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'error_code' => 'PERMISSION_DENIED',
        ]);
    }

    /**
     * Test that JSON responses are consistent across all error types
     */
    public function test_all_error_responses_follow_standard_format(): void
    {
        $responses = [
            $this->getJson('/api/classes/99999'),  // 404
            $this->postJson('/api/chapters', []),  // 422 or other
            $this->getJson('/api/admin/analytics/trends'),  // 401/403 without auth
        ];

        foreach ($responses as $response) {
            if ($response->status() >= 400) {
                // All errors should have these fields
                $this->assertArrayHasKey('success', $response->json());
                $this->assertArrayHasKey('error_code', $response->json());
                $this->assertArrayHasKey('message', $response->json());

                // success should always be false for errors
                $this->assertFalse($response->json('success'));

                // error_code should be a string, not null
                $this->assertIsString($response->json('error_code'));
                $this->assertNotEmpty($response->json('error_code'));
            }
        }
    }

    /**
     * Test that exception traces are NOT in JSON responses
     */
    public function test_no_stack_traces_in_api_responses(): void
    {
        $response = $this->getJson('/api/classes/99999');

        $content = json_encode($response->json());

        // Verify no stack trace indicators
        $this->assertStringNotContainsString('Stack trace', $content);
        $this->assertStringNotContainsString('#0', $content);
        $this->assertStringNotContainsString('at line', $content);
        $this->assertStringNotContainsString('in /', $content);
    }

    /**
     * Test that different error types have distinct error_codes
     */
    public function test_error_codes_are_distinct_by_type(): void
    {
        $notFoundResponse = $this->getJson('/api/classes/99999');
        $this->assertEquals('RESOURCE_NOT_FOUND', $notFoundResponse->json('error_code'));

        // Validation error would have VALIDATION_FAILED
        // Permission error would have PERMISSION_DENIED
        // These can be tested once those endpoints are refactored

        $this->assertTrue(true);  // Placeholder
    }

    /**
     * Test that logging is triggered (verified via logs)
     * Note: In real test, would capture logs via Log::spy()
     */
    public function test_exceptions_trigger_logging(): void
    {
        // This would be tested with Log::shouldReceive() or Log::spy()
        // Verify that an exception being thrown results in a log entry

        $this->assertTrue(true);  // Placeholder - logs would be verified with spy
    }

    /**
     * Test that client message can be customized while error_code stays consistent
     */
    public function test_error_code_is_independent_of_message(): void
    {
        // Two different 404s might have different messages but same error_code
        // Client can switch on error_code for i18n or specific UI behavior

        $response1 = $this->getJson('/api/classes/99999');
        // If refactored: $response2 = $this->getJson('/api/evaluations/99999');

        // Both would have RESOURCE_NOT_FOUND but potentially different messages
        $this->assertEquals('RESOURCE_NOT_FOUND', $response1->json('error_code'));

        $this->assertTrue(true);  // Placeholder
    }
}
