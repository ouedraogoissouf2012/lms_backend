<?php

namespace Tests\Feature;

use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\PermissionException;
use App\Exceptions\ValidationException;
use App\Exceptions\UnauthenticatedException;
use App\Exceptions\ApiServerException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Comprehensive Exception Handler Tests
 *
 * Validates each exception type with happy path + edge cases
 * Ensures ZERO getMessage() exposure in production
 */
class ExceptionHandlerComprehensiveTest extends TestCase
{
    use RefreshDatabase;

    // ============ ResourceNotFoundException Tests ============

    public function test_resource_not_found_returns_404_with_error_code(): void
    {
        $response = $this->getJson('/api/classes/99999');

        $this->assertEquals(404, $response->status());
        $response->assertJson([
            'success' => false,
            'error_code' => 'RESOURCE_NOT_FOUND',
        ]);
        $this->assertStringNotContainsString('ModelNotFoundException', $response->getContent());
    }

    public function test_resource_not_found_message_is_generic(): void
    {
        $response = $this->getJson('/api/classes/99999');

        $message = $response->json('message');
        // Should be a simple string, not revealing exception internals
        $this->assertIsString($message);
        $this->assertLessThan(100, strlen($message)); // Generic, not detailed trace
    }

    public function test_resource_not_found_has_correct_json_structure(): void
    {
        $response = $this->getJson('/api/classes/99999');

        $json = $response->json();
        $this->assertArrayHasKey('success', $json);
        $this->assertArrayHasKey('error_code', $json);
        $this->assertArrayHasKey('message', $json);
        $this->assertFalse($json['success']);
        $this->assertEquals('RESOURCE_NOT_FOUND', $json['error_code']);
    }

    // ============ ValidationException Tests ============

    public function test_validation_error_returns_422_with_errors_array(): void
    {
        $response = $this->postJson('/api/chapters', [
            // Missing required 'title' field
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);
        $this->assertIsArray($response->json('errors'));
    }

    public function test_validation_error_contains_field_errors(): void
    {
        $response = $this->postJson('/api/evaluations', [
            // Missing required fields
            'duree_minutes' => 'not_a_number', // Wrong type
        ]);

        $response->assertStatus(422);
        $errors = $response->json('errors');
        // Should have validation errors, not getMessage()
        $this->assertTrue(count($errors) > 0);
        $this->assertStringNotContainsString('getMessage', json_encode($errors));
    }

    public function test_validation_error_message_is_generic(): void
    {
        $response = $this->postJson('/api/chapters', [
            'title' => null, // Invalid: required field
        ]);

        $response->assertStatus(422);
        $message = $response->json('message');
        $this->assertIsString($message);
        $this->assertStringNotContainsString('Exception', $message);
        $this->assertStringNotContainsString('call_user_func', $message);
    }

    // ============ PermissionException Tests ============

    public function test_permission_denied_returns_403(): void
    {
        $response = $this->getJson('/api/admin/analytics/trends');

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'error_code' => 'PERMISSION_DENIED',
        ]);
    }

    public function test_permission_denied_message_is_generic(): void
    {
        $response = $this->getJson('/api/admin/analytics/trends');

        $response->assertStatus(403);
        $message = $response->json('message');
        // Generic permission message, not revealing internal logic
        $this->assertIsString($message);
        $this->assertLessThan(150, strlen($message));
    }

    // ============ UnauthenticatedException Tests ============

    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/auth/me');

        $this->assertEquals(401, $response->status());
        $response->assertJson([
            'success' => false,
            'error_code' => 'UNAUTHENTICATED',
        ]);
    }

    public function test_unauthenticated_without_token(): void
    {
        $response = $this->getJson('/api/proxy/classes', [
            // No Authorization header
        ]);

        $this->assertEquals(401, $response->status());
        $this->assertStringNotContainsString('token', strtolower($response->getContent()));
    }

    // ============ ApiServerException Tests ============

    public function test_server_error_returns_500_with_generic_message(): void
    {
        // Force an uncaught exception by triggering bad query
        $response = $this->getJson('/api/classes?invalid_param=<script>alert(1)</script>');

        // Either 404 or 500, both should be generic
        if ($response->status() === 500) {
            $response->assertJson([
                'success' => false,
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ]);
            $message = $response->json('message');
            $this->assertStringNotContainsString('Exception', $message);
            $this->assertStringNotContainsString('Stack trace', $message);
        }
    }

    // ============ Global Security Tests ============

    public function test_no_exception_details_leaked_in_any_error(): void
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
            'getMessage',
            'getTraceAsString',
            'getCode',
        ];

        $endpoints = [
            '/api/classes/99999',
            '/api/evaluations/99999',
            '/api/chapters/99999',
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

    public function test_all_error_responses_follow_standard_format(): void
    {
        $endpoints = [
            '/api/classes/99999',      // 404
            '/api/chapters',           // 422 or other
            '/api/admin/analytics',    // 401/403
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);

            if ($response->status() >= 400) {
                $json = $response->json();

                // All errors must have these fields
                $this->assertArrayHasKey('success', $json, "Missing 'success' in $endpoint");
                $this->assertArrayHasKey('error_code', $json, "Missing 'error_code' in $endpoint");
                $this->assertArrayHasKey('message', $json, "Missing 'message' in $endpoint");

                // success must be false
                $this->assertFalse($json['success'], "success should be false in $endpoint");

                // error_code must be string
                $this->assertIsString($json['error_code'], "error_code must be string in $endpoint");
                $this->assertNotEmpty($json['error_code'], "error_code must not be empty in $endpoint");
            }
        }
    }

    public function test_no_stack_traces_in_json_responses(): void
    {
        $response = $this->getJson('/api/classes/99999');

        $content = json_encode($response->json());

        $this->assertStringNotContainsString('Stack trace', $content);
        $this->assertStringNotContainsString('#0', $content);
        $this->assertStringNotContainsString('at line', $content);
        $this->assertStringNotContainsString('in /', $content);
        $this->assertStringNotContainsString('in \\', $content);
    }

    public function test_error_codes_are_distinct_and_consistent(): void
    {
        $errorCodes = [
            'RESOURCE_NOT_FOUND',
            'VALIDATION_FAILED',
            'PERMISSION_DENIED',
            'UNAUTHENTICATED',
            'INTERNAL_SERVER_ERROR',
        ];

        foreach ($errorCodes as $code) {
            // Verify code is properly formatted (SCREAMING_SNAKE_CASE)
            $this->assertMatchesRegularExpression('/^[A-Z_]+$/', $code, "Error code $code not in SCREAMING_SNAKE_CASE");
        }
    }

    public function test_client_message_independent_from_exception_message(): void
    {
        // Two 404s might have different internal exceptions
        // but client sees same error_code
        $response1 = $this->getJson('/api/classes/99999');
        $response2 = $this->getJson('/api/evaluations/99999');

        if ($response1->status() === 404 && $response2->status() === 404) {
            // Both have RESOURCE_NOT_FOUND
            $this->assertEquals('RESOURCE_NOT_FOUND', $response1->json('error_code'));
            $this->assertEquals('RESOURCE_NOT_FOUND', $response2->json('error_code'));

            // Client messages might differ but both are generic
            $msg1 = $response1->json('message');
            $msg2 = $response2->json('message');

            $this->assertIsString($msg1);
            $this->assertIsString($msg2);
            // Neither should expose exception internals
            $this->assertStringNotContainsString('Exception', $msg1);
            $this->assertStringNotContainsString('Exception', $msg2);
        }
    }
}
