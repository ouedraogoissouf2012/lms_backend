<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * #549 — corrélation des logs API via X-Request-Id.
 */
final class AssignRequestIdTest extends TestCase
{
    public function test_api_response_carries_a_generated_request_id(): void
    {
        $response = $this->getJson('/api/auth/check');

        $id = $response->headers->get('X-Request-Id');
        self::assertNotEmpty($id);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._-]{8,128}$/', (string) $id);
    }

    public function test_valid_client_request_id_is_echoed(): void
    {
        $this->withHeaders(['X-Request-Id' => 'corr-549-abc'])
            ->getJson('/api/auth/check')
            ->assertHeader('X-Request-Id', 'corr-549-abc');
    }

    public function test_unsafe_client_request_id_is_replaced(): void
    {
        $response = $this->withHeaders(['X-Request-Id' => "bad\nid"])
            ->getJson('/api/auth/check');

        self::assertNotSame("bad\nid", $response->headers->get('X-Request-Id'));
        self::assertMatchesRegularExpression(
            '/^[A-Za-z0-9._-]{8,128}$/',
            (string) $response->headers->get('X-Request-Id'),
        );
    }
}
