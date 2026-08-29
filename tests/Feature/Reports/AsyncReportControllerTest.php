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

final class AsyncReportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_can_be_enqueued_without_blocking_http_response(): void
    {
        Queue::fake();
        $coordinator = $this->coordinator();
        $token = $coordinator->createToken('async-report-test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/admin/reports/attendance?async=1', [
            'date_start' => '2026-07-01',
            'date_end' => '2026-07-07',
        ]);

        $response->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure([
                'data' => ['id', 'status', 'status_url', 'download_url'],
            ]);

        Queue::assertPushedOn('low', GenerateReportPdf::class);

        $statusUrl = $response->json('data.status_url');
        $this->assertIsString($statusUrl);

        $this->withToken($token)->getJson($statusUrl)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.user_id');
    }

    private function coordinator(): User
    {
        $institution = Institution::factory()->create(['slug' => 'school-a']);
        app(TenantManager::class)->set($institution);

        return User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'coordinateur',
        ]);
    }
}
