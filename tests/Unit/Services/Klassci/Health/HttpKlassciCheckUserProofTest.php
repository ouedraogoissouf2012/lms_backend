<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Klassci\Health;

use App\Services\Klassci\Health\HttpKlassciCheckUserProof;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * #713 — le POST check-user distingue page parquée et payload KLASSCI.
 */
final class HttpKlassciCheckUserProofTest extends TestCase
{
    public function test_html_404_is_not_klassci(): void
    {
        Http::fake([
            'parked.test/api/lms/auth/check-user' => Http::response('<html>nginx</html>', 404),
        ]);

        $result = app(HttpKlassciCheckUserProof::class)->verify('https://parked.test/api/lms');

        $this->assertSame('pas_klassci', $result->kind);
        $this->assertSame(404, $result->statusCode);
    }

    public function test_json_without_data_key_is_not_klassci(): void
    {
        Http::fake([
            'x.test/api/lms/auth/check-user' => Http::response(['foo' => 1], 200),
        ]);

        $result = app(HttpKlassciCheckUserProof::class)->verify('https://x.test/api/lms');

        $this->assertSame('pas_klassci', $result->kind);
    }

    public function test_check_user_data_key_is_ok(): void
    {
        Http::fake([
            'k.test/api/lms/auth/check-user' => Http::response(['data' => ['found' => false]], 200),
        ]);

        $result = app(HttpKlassciCheckUserProof::class)->verify('https://k.test/api/lms');

        $this->assertTrue($result->isOk());
    }

    public function test_http_500_is_klassci_error(): void
    {
        Http::fake([
            'k.test/api/lms/auth/check-user' => Http::response(['data' => []], 500),
        ]);

        $result = app(HttpKlassciCheckUserProof::class)->verify('https://k.test/api/lms');

        $this->assertSame('klassci_erreur', $result->kind);
    }
}
