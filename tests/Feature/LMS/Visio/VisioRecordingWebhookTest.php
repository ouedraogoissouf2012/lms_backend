<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Enums\SeanceRecordingStatus;
use App\Jobs\ProcessSeanceRecordingReady;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\SeanceRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * #469 — webhook recording-ready signé / anti-replay.
 */
final class VisioRecordingWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret-469';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.visio.webhook_secret' => self::SECRET]);
    }

    public function test_valid_signature_dispatches_processing_job(): void
    {
        Queue::fake();
        $recording = $this->recording();
        $body = [
            'recording_id' => $recording->id,
            'url' => 'https://recordings.example/a.mp4',
            'provider' => 'external',
        ];

        $this->signedPost($body)->assertStatus(202);
        Queue::assertPushedOn('low', ProcessSeanceRecordingReady::class);
    }

    public function test_missing_secret_is_unavailable(): void
    {
        config(['services.visio.webhook_secret' => '']);
        $this->postJson('/api/webhooks/visio/recording-ready', [
            'recording_id' => 1,
            'url' => 'https://recordings.example/a.mp4',
        ])->assertStatus(503);
    }

    public function test_bad_signature_is_rejected(): void
    {
        $this->postJson('/api/webhooks/visio/recording-ready', [
            'recording_id' => 1,
            'url' => 'https://recordings.example/a.mp4',
        ], [
            'X-Visio-Signature' => 'sha256=deadbeef',
            'X-Visio-Timestamp' => (string) time(),
            'X-Visio-Nonce' => 'nonce-1',
        ])->assertStatus(401);
    }

    public function test_replayed_nonce_is_rejected(): void
    {
        Queue::fake();
        $recording = $this->recording();
        $body = [
            'recording_id' => $recording->id,
            'url' => 'https://recordings.example/a.mp4',
        ];
        $headers = $this->sign($body, 'nonce-replay');

        $this->postJson('/api/webhooks/visio/recording-ready', $body, $headers)->assertStatus(202);
        $this->postJson('/api/webhooks/visio/recording-ready', $body, $headers)->assertStatus(409);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function signedPost(array $body, string $nonce = 'nonce-ok'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/webhooks/visio/recording-ready', $body, $this->sign($body, $nonce));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, string>
     */
    private function sign(array $body, string $nonce): array
    {
        $timestamp = (string) time();
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        $hmac = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$raw, self::SECRET);

        return [
            'X-Visio-Signature' => 'sha256='.$hmac,
            'X-Visio-Timestamp' => $timestamp,
            'X-Visio-Nonce' => $nonce,
        ];
    }

    private function recording(): SeanceRecording
    {
        $institution = Institution::factory()->create();
        $seance = Seance::factory()->create(['institution_id' => $institution->id]);

        return SeanceRecording::factory()->forSeance($seance)->create([
            'status' => SeanceRecordingStatus::Processing,
        ]);
    }
}
