<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Jobs\GenerateReportPdf;
use App\Models\Institution;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * #547 R1 — la génération PDF est ASYNCHRONE par défaut (queue `low`).
 * Le mode synchrone (PDF binaire inline) reste accessible via un opt-out
 * EXPLICITE `?sync=1` / `Prefer: respond-sync`. Priorité : async l'emporte.
 */
final class ReportAsyncByDefaultTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function payload(): array
    {
        return ['/api/admin/reports/attendance', [
            'date_start' => '2026-07-01',
            'date_end' => '2026-07-07',
        ]];
    }

    private function coordinatorToken(): string
    {
        $institution = Institution::factory()->create(['slug' => 'school-async-default']);
        app(TenantManager::class)->set($institution);
        $coordinator = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'coordinateur',
        ]);

        return $coordinator->createToken('async-default-test')->plainTextToken;
    }

    public function test_report_without_any_flag_is_enqueued_async(): void
    {
        Queue::fake();
        [$url, $body] = $this->payload();

        $this->withToken($this->coordinatorToken())
            ->postJson($url, $body)
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushedOn('low', GenerateReportPdf::class);
    }

    public function test_explicit_sync_opt_out_returns_binary_pdf(): void
    {
        Queue::fake();
        [$url, $body] = $this->payload();

        $response = $this->withToken($this->coordinatorToken())
            ->postJson($url.'?sync=1', $body);

        $response->assertOk();
        self::assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        Queue::assertNothingPushed();
    }

    public function test_prefer_respond_sync_header_opts_out_to_synchronous(): void
    {
        Queue::fake();
        [$url, $body] = $this->payload();

        $this->withToken($this->coordinatorToken())
            ->withHeaders(['Prefer' => 'respond-sync'])
            ->postJson($url, $body)
            ->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_legacy_async_flag_still_returns_202(): void
    {
        Queue::fake();
        [$url, $body] = $this->payload();

        $this->withToken($this->coordinatorToken())
            ->postJson($url.'?async=1', $body)
            ->assertAccepted();

        Queue::assertPushedOn('low', GenerateReportPdf::class);
    }

    public function test_async_wins_when_both_flags_present(): void
    {
        Queue::fake();
        [$url, $body] = $this->payload();

        // Défaut sûr (async) l'emporte sur l'opt-out (Requirement 1.4).
        $this->withToken($this->coordinatorToken())
            ->postJson($url.'?sync=1&async=1', $body)
            ->assertAccepted();

        Queue::assertPushedOn('low', GenerateReportPdf::class);
    }
}
