<?php

declare(strict_types=1);

namespace Tests\Feature\Institution;

use App\Models\Institution;
use App\Services\Institution\InstitutionConnectionTester;
use App\Services\Klassci\Health\ApplicationProofResult;
use App\Services\Klassci\Health\InMemoryKlassciApplicationProof;
use App\Services\Klassci\Health\InMemoryKlassciReachability;
use App\Services\Klassci\Health\KlassciApplicationProof;
use App\Services\Klassci\Health\KlassciReachability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #713 — un 404 de page parquée n'est plus « Connexion KLASSCI réussie ».
 * Échouait tant que le tester prenait `ReachabilityMeasure::reachable` pour preuve.
 */
final class InstitutionConnectionTesterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_parked_404_is_not_a_klassci_success(): void
    {
        $url = 'https://parked.example.test/api/lms';
        $institution = Institution::factory()->create(['klassci_api_url' => $url]);
        $this->bindReachable($url, 404);
        $this->bindProof($url, ApplicationProofResult::notKlassci(404, 12));

        $result = app(InstitutionConnectionTester::class)->test($institution->id);

        $this->assertSame(502, $result['status']);
        $this->assertFalse($result['payload']['success']);
        $this->assertSame('pas_klassci', $result['payload']['reason']);
        $this->assertStringContainsString('pas KLASSCI', $result['payload']['message']);
    }

    public function test_unexpected_json_is_rejected(): void
    {
        $url = 'https://parked.example.test/api/lms';
        $institution = Institution::factory()->create(['klassci_api_url' => $url]);
        $this->bindReachable($url, 200);
        $this->bindProof($url, ApplicationProofResult::notKlassci(200, 8));

        $result = app(InstitutionConnectionTester::class)->test($institution->id);

        $this->assertSame('pas_klassci', $result['payload']['reason']);
    }

    public function test_unreachable_host_has_its_own_message(): void
    {
        $url = 'https://down.example.test/api/lms';
        $institution = Institution::factory()->create(['klassci_api_url' => $url]);
        $this->app->instance(
            KlassciReachability::class,
            (new InMemoryKlassciReachability)->unreachable($url, 'cURL error 28'),
        );

        $result = app(InstitutionConnectionTester::class)->test($institution->id);

        $this->assertSame(502, $result['status']);
        $this->assertSame('injoignable', $result['payload']['reason']);
        $this->assertSame('Serveur injoignable', $result['payload']['message']);
    }

    public function test_klassci_application_error_has_its_own_message(): void
    {
        $url = 'https://klassci.example.test/api/lms';
        $institution = Institution::factory()->create(['klassci_api_url' => $url]);
        $this->bindReachable($url, 200);
        $this->bindProof($url, ApplicationProofResult::klassciError(500, 40));

        $result = app(InstitutionConnectionTester::class)->test($institution->id);

        $this->assertSame('klassci_erreur', $result['payload']['reason']);
        $this->assertStringContainsString('erreur', $result['payload']['message']);
    }

    public function test_check_user_shape_is_success(): void
    {
        $url = 'https://klassci.example.test/api/lms';
        $institution = Institution::factory()->create(['klassci_api_url' => $url]);
        $this->bindReachable($url, 200);
        $this->bindProof($url, ApplicationProofResult::ok(200, 20));

        $result = app(InstitutionConnectionTester::class)->test($institution->id);

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['payload']['success']);
    }

    private function bindReachable(string $url, int $status): void
    {
        $this->app->instance(
            KlassciReachability::class,
            (new InMemoryKlassciReachability)->reachable($url, 15, $status),
        );
    }

    private function bindProof(string $url, ApplicationProofResult $result): void
    {
        $this->app->instance(
            KlassciApplicationProof::class,
            (new InMemoryKlassciApplicationProof)->willReturn($url, $result),
        );
    }
}
