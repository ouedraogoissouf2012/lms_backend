<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExceptionHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_uncaught_exception_returns_generic_message_in_production(): void
    {
        $this->app['config']->set('app.debug', false);

        $this->get('/api/test-throw-exception', [
            'Accept' => 'application/json',
        ])->assertJson([
            'success' => false,
            'message' => 'Une erreur est survenue.',
        ])->assertStatus(500);
    }

    public function test_validation_exception_returns_422(): void
    {
        $this->post('/api/auth/login', [], [
            'Accept' => 'application/json',
        ])->assertStatus(422);
    }

    public function test_json_response_never_contains_exception_detail(): void
    {
        $this->app['config']->set('app.debug', false);

        // Faire une requête qui provoque une exception
        $response = $this->get('/api/test-throw-exception', [
            'Accept' => 'application/json',
        ]);

        $body = $response->getContent();

        // Vérifier qu'il n'y a pas d'exception details
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('PDOException', $body);
        $this->assertStringNotContainsString('Exception', $body);
        $this->assertStringNotContainsString('at ', $body);
        $this->assertStringNotContainsString('trace', $body);
    }

    public function test_getMessage_not_exposed_in_controllers(): void
    {
        // Grep validation - exécuté en CLI, pas en test HTTP
        $output = shell_exec('grep -r "response()->json" app/Http/Controllers/ 2>/dev/null | grep "getMessage()" | wc -l');
        $this->assertEquals(0, trim($output), 'getMessage() should not be exposed in JSON responses');
    }

    public function test_log_still_contains_exception_details(): void
    {
        // Les logs serveur doivent CONSERVER getMessage()
        $output = shell_exec('grep -r "Log::" app/Http/Controllers/ 2>/dev/null | grep "getMessage()" | wc -l');
        $this->assertGreaterThan(100, trim($output), 'Log:: should still contain getMessage() for server-side logging');
    }
}
