<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Caractérisation (axe #1) — fige la forme JSON EXACTE des 4 endpoints de
 * {@see \App\Http\Controllers\API\AdminAnalyticsController} AVANT la migration
 * vers le trait {@see \App\Http\Controllers\Concerns\RespondsWithJson}.
 *
 * Le contrat actuel de ces 4 endpoints est `{ success: true, data: <payload> }`
 * — aucune clé `message`/`meta`. Ce test verrouille ce contrat pour prouver
 * que la migration `successResponse($data)` produit une sortie identique.
 *
 * Auth : token Bearer (et non `Sanctum::actingAs`) afin que le middleware
 * `ResolveInstitution` résolve le tenant — les services analytics appellent
 * `TenantManager::getResolvedSlug()` qui lève une exception sans tenant résolu
 * (cf. AdminAnalyticsCacheIsolationTest, même approche).
 *
 * @see app/Http/Controllers/API/AdminAnalyticsController.php
 */
final class AdminAnalyticsResponseTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $institution = Institution::factory()->create(['slug' => 'school-a']);
        $this->coordinator = User::factory()->create([
            'institution_id' => $institution->id,
            'role'           => 'coordinateur',
        ]);
    }

    public function test_activity_trends_returns_success_data_envelope(): void
    {
        $this->assertSuccessDataShape($this->getAnalytics('activity-trends'));
    }

    public function test_system_metrics_returns_success_data_envelope(): void
    {
        $this->assertSuccessDataShape($this->getAnalytics('system-metrics'));
    }

    public function test_system_metrics_exposes_cpanel_safe_backpressure_signals(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'reports',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinute()->timestamp,
            'created_at' => now()->subMinutes(5)->timestamp,
        ]);
        DB::table('jobs')->insert([
            'queue' => 'reports',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->addMinute()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(),
            'connection' => 'database',
            'queue' => 'reports',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        $response = $this->getAnalytics('system-metrics');

        $response->assertJsonPath('data.backpressure.queue.available', true);
        $response->assertJsonPath('data.backpressure.queue.pending', 1);
        $response->assertJsonPath('data.backpressure.queue.delayed', 1);
        $response->assertJsonPath('data.backpressure.queue.failed', 1);
        $response->assertJsonPath('data.backpressure.queue.queues.reports', 1);
        $response->assertJsonPath('data.backpressure.klassci.timeout_seconds', 5);
        $this->assertGreaterThanOrEqual(
            240,
            $response->json('data.backpressure.queue.oldest_pending_age_seconds')
        );
    }

    public function test_pending_tasks_returns_success_data_envelope(): void
    {
        $this->assertSuccessDataShape($this->getAnalytics('pending-tasks'));
    }

    public function test_recent_users_returns_success_data_envelope(): void
    {
        $this->assertSuccessDataShape($this->getAnalytics('recent-users'));
    }

    /**
     * Le contrat figé : statut 200, clés racine STRICTEMENT `['success','data']`
     * (pas de `message` ni `meta`), `success === true`.
     */
    private function assertSuccessDataShape(TestResponse $response): void
    {
        $response->assertStatus(200);

        $json = $response->json();
        $this->assertSame(['success', 'data'], array_keys($json));
        $this->assertTrue($json['success']);
        $this->assertArrayHasKey('data', $json);
    }

    private function getAnalytics(string $endpoint): TestResponse
    {
        $token = $this->coordinator->createToken('analytics-response-test')->plainTextToken;

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/admin/analytics/' . $endpoint);
    }
}
