<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Enums\SeanceRecordingStatus;
use App\Models\Seance;
use App\Models\SeanceRecording;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

final class SeanceRecordingControlService
{
    private const CACHE_TTL_SECONDS = 1_209_600; // 14 days

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly SeanceRecordingAccessService $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function start(int $seanceId, User $user): array
    {
        $seance = $this->resolveSeance($seanceId);
        if ($seance === null) {
            return $this->fail(404, 'Seance non trouvee');
        }

        if (! $this->access->canControl($seance, $user)) {
            return $this->fail(403, 'Acces reserve a l enseignant proprietaire');
        }

        $recording = $this->latestRecording($seance);
        $created = false;
        if ($recording === null || ! $recording->status->isActive()) {
            $recording = SeanceRecording::query()->create([
                'seance_id' => $seance->id,
                'status' => SeanceRecordingStatus::Recording,
                'started_at' => now(),
            ]);
            $created = true;
        }

        $this->cachePayload($seance, $recording);
        if ($created) {
            $this->auditRecording('visio_recording_start', $seance, $recording);
        }

        return $this->ok($recording->toRecordingPayload());
    }

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function stop(int $seanceId, User $user): array
    {
        $seance = $this->resolveSeance($seanceId);
        if ($seance === null) {
            return $this->fail(404, 'Seance non trouvee');
        }

        if (! $this->access->canControl($seance, $user)) {
            return $this->fail(403, 'Acces reserve a l enseignant proprietaire');
        }

        $recording = $this->latestRecording($seance);
        if ($recording === null) {
            return $this->ok($this->idleState($seance));
        }

        if ($recording->status === SeanceRecordingStatus::Recording) {
            $recording->update([
                'status' => SeanceRecordingStatus::Processing,
                'stopped_at' => now(),
            ]);
            $this->auditRecording('visio_recording_stop', $seance, $recording->refresh());
        }

        $this->cachePayload($seance, $recording->refresh());

        return $this->ok($recording->toRecordingPayload());
    }

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function status(int $seanceId, User $user): array
    {
        $seance = $this->resolveSeance($seanceId);
        if ($seance === null) {
            return $this->fail(404, 'Seance non trouvee');
        }

        if (! $this->access->canRead($seance, $user)) {
            return $this->fail(403, 'Acces non autorise a cette seance');
        }

        $recording = $this->latestRecording($seance);
        if ($recording !== null) {
            $this->auditRecording('visio_recording_read', $seance, $recording);
        }

        return $this->ok($this->state($seance));
    }

    private function resolveSeance(int $seanceId): ?Seance
    {
        return Seance::query()->find($seanceId)
            ?? Seance::query()->where('klassci_seance_id', $seanceId)->first();
    }

    private function latestRecording(Seance $seance): ?SeanceRecording
    {
        return SeanceRecording::query()
            ->where('seance_id', $seance->id)
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Seance $seance): array
    {
        $state = $this->cache->get($this->cacheKey($seance));
        $recording = $this->latestRecording($seance);
        if ($recording !== null) {
            $payload = $recording->toRecordingPayload();
            $this->cachePayload($seance, $recording);

            return $payload;
        }

        if (is_array($state)) {
            return $this->normalizeState($seance, $state);
        }

        return $this->idleState($seance);
    }

    /**
     * @param  array<array-key, mixed>  $state
     * @return array<string, mixed>
     */
    private function normalizeState(Seance $seance, array $state): array
    {
        return [
            'id' => $this->stringValue($state['id'] ?? null, $this->recordingId($seance)),
            'status' => $this->stringValue($state['status'] ?? null, 'idle'),
            'url' => $this->stringOrNull($state['url'] ?? null),
            'started_at' => $this->stringOrNull($state['started_at'] ?? null),
            'stopped_at' => $this->stringOrNull($state['stopped_at'] ?? null),
            'processed_at' => $this->stringOrNull($state['processed_at'] ?? null),
            'error_message' => $this->stringOrNull($state['error_message'] ?? null),
            'is_recording' => ($state['status'] ?? null) === 'recording',
            'consent_required' => in_array($state['status'] ?? null, SeanceRecordingStatus::activeValues(), true),
            'consent_message' => $this->consentMessage(),
            'retention_days' => 365,
            'can_download' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function idleState(Seance $seance): array
    {
        return [
            'id' => $this->recordingId($seance),
            'status' => 'idle',
            'url' => null,
            'started_at' => null,
            'stopped_at' => null,
            'processed_at' => null,
            'error_message' => null,
            'is_recording' => false,
            'consent_required' => false,
            'consent_message' => $this->consentMessage(),
            'retention_days' => 365,
            'can_download' => false,
        ];
    }

    private function cacheKey(Seance $seance): string
    {
        return 'visio:recording:seance:'.$seance->id;
    }

    private function cachePayload(Seance $seance, SeanceRecording $recording): void
    {
        $this->cache->put($this->cacheKey($seance), $recording->toRecordingPayload(), self::CACHE_TTL_SECONDS);
    }

    private function auditRecording(string $action, Seance $seance, SeanceRecording $recording): void
    {
        $this->audit->logSecurityEvent($action, $recording, [
            'seance_id' => $seance->id,
            'klassci_seance_id' => $seance->klassci_seance_id,
            'status' => $recording->status->value,
        ]);
    }

    private function recordingId(Seance $seance): string
    {
        return 'seance-'.$seance->id.'-recording';
    }

    private function stringValue(mixed $value, string $fallback): string
    {
        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function consentMessage(): string
    {
        return 'Cette seance peut etre enregistree. En restant dans la visio, vous acceptez que votre participation soit captee selon les regles de votre etablissement.';
    }

    /**
     * @param  array<string, mixed>  $recording
     * @return array{status:int, payload: array<string, mixed>}
     */
    private function ok(array $recording): array
    {
        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'data' => ['recording' => $recording],
            ],
        ];
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
