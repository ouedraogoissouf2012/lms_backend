<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Jobs\ProcessSeanceRecordingReady;
use App\Models\SeanceRecording;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;

/**
 * #469 — finalisation recording : webhook signé, horodaté, anti-replay.
 * Ne capture pas la vidéo. Dispatche {@see ProcessSeanceRecordingReady}.
 */
final class SeanceRecordingWebhookService
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function accept(
        string $rawBody,
        ?string $signature,
        ?string $timestamp,
        ?string $nonce,
        array $payload,
    ): array {
        $secret = config('services.visio.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            return $this->fail(503, 'Webhook enregistrement non configuré.');
        }

        $auth = $this->authenticate($rawBody, $signature, $timestamp, $nonce, $secret);
        if ($auth !== null) {
            return $auth;
        }

        $recordingId = isset($payload['recording_id']) && is_numeric($payload['recording_id'])
            ? (int) $payload['recording_id']
            : 0;
        $url = isset($payload['url']) && is_string($payload['url']) ? $payload['url'] : '';
        if ($recordingId < 1 || $url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->fail(422, 'Payload enregistrement invalide.');
        }

        $recording = SeanceRecording::withoutGlobalScope('institution')->find($recordingId);
        if ($recording === null) {
            return $this->fail(404, 'Enregistrement introuvable.');
        }

        $title = isset($payload['title']) && is_string($payload['title']) ? $payload['title'] : null;
        $provider = isset($payload['provider']) && is_string($payload['provider'])
            ? $payload['provider']
            : 'external';

        ProcessSeanceRecordingReady::dispatch($recording->id, $url, $title, $provider)
            ->onQueue('low');

        $this->logger->info('visio.recording.webhook.accepted', [
            'recording_id' => $recording->id,
        ]);

        return [
            'status' => 202,
            'payload' => [
                'success' => true,
                'message' => 'Enregistrement accepté pour traitement.',
            ],
        ];
    }

    /**
     * @return array{status:int, payload: array<string, mixed>}|null
     */
    private function authenticate(
        string $rawBody,
        ?string $signature,
        ?string $timestamp,
        ?string $nonce,
        string $secret,
    ): ?array {
        if ($signature === null || $timestamp === null || $nonce === null
            || $signature === '' || $timestamp === '' || $nonce === '') {
            return $this->fail(401, 'Signature webhook manquante.');
        }

        if (! ctype_digit($timestamp)) {
            return $this->fail(401, 'Horodatage webhook invalide.');
        }

        $maxAge = (int) config('services.visio.webhook_max_age', 300);
        if (abs(time() - (int) $timestamp) > $maxAge) {
            return $this->fail(401, 'Horodatage webhook expiré.');
        }

        $expected = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$rawBody, $secret);
        $provided = str_starts_with($signature, 'sha256=')
            ? substr($signature, 7)
            : $signature;
        if (! hash_equals($expected, $provided)) {
            return $this->fail(401, 'Signature webhook invalide.');
        }

        $nonceKey = 'visio_webhook_nonce_'.$nonce;
        if (! $this->cache->add($nonceKey, 1, $maxAge + 60)) {
            return $this->fail(409, 'Webhook déjà traité.');
        }

        return null;
    }

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    private function fail(int $status, string $message): array
    {
        return [
            'status' => $status,
            'payload' => [
                'success' => false,
                'message' => $message,
            ],
        ];
    }
}
