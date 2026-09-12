<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Jobs\GenerateReportPdf;
use App\Models\Institution;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

final class AsyncReportControllerTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    public function test_report_can_be_enqueued_without_blocking_http_response(): void
    {
        Queue::fake();
        $coordinator = $this->coordinator();

        // Les deux appels sont faits par le MEME coordinateur : la memoisation du
        // garde serait ici inoffensive. On passe quand meme par `asTenant()`
        // (#691) — il purge le garde avant de poser le jeton, et lever
        // l'ambiguite coute moins cher que de la reexpliquer a chaque relecture.
        $response = $this->asTenant($coordinator)->postJson('/api/admin/reports/attendance?async=1', [
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

        $this->asTenant($coordinator)->getJson($statusUrl)
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
