<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Enums\SeanceRecordingStatus;
use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\SeanceRecording;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

final class SeanceRecordingControlService
{
    private const CACHE_TTL_SECONDS = 1_209_600; // 14 days

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function start(int $seanceId, User $user): array
    {
        $seance = $this->resolveSeance($seanceId);
        if ($seance === null) {
            return $this->fail(404, 'Seance non trouvee');
        }

        if (! $this->canControlRecording($seance, $user)) {
            return $this->fail(403, 'Acces reserve a l enseignant proprietaire');
        }

        $recording = $this->latestRecording($seance);
        if ($recording === null || ! $recording->status->isActive()) {
            $recording = SeanceRecording::query()->create([
                'seance_id' => $seance->id,
                'status' => SeanceRecordingStatus::Recording,
                'started_at' => now(),
            ]);
        }

        $this->cachePayload($seance, $recording);

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

        if (! $this->canControlRecording($seance, $user)) {
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

        if (! $this->canReadRecording($seance, $user)) {
            return $this->fail(403, 'Acces non autorise a cette seance');
        }

        return $this->ok($this->state($seance));
    }

    private function resolveSeance(int $seanceId): ?Seance
    {
        return Seance::query()->find($seanceId)
            ?? Seance::query()->where('klassci_seance_id', $seanceId)->first();
    }

    private function canControlRecording(Seance $seance, User $user): bool
    {
        return $user->isTeacher() && $this->teacherOwnsSeance($seance, $user);
    }

    private function latestRecording(Seance $seance): ?SeanceRecording
    {
        return SeanceRecording::query()
            ->where('seance_id', $seance->id)
            ->latest('id')
            ->first();
    }

    private function canReadRecording(Seance $seance, User $user): bool
    {
        if ($user->isManager() || $this->teacherOwnsSeance($seance, $user)) {
            return true;
        }

        if ($seance->klassci_classe_id !== null && $this->studentBelongsToSeanceClass($seance, $user)) {
            return true;
        }

        return ESBTPAttendance::query()
            ->where('seance_id', $seance->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    private function teacherOwnsSeance(Seance $seance, User $user): bool
    {
        if ($seance->klassci_enseignant_id === null) {
            return false;
        }

        $seanceTeacherId = (int) $seance->klassci_enseignant_id;

        return in_array($seanceTeacherId, $this->userTeacherIds($user), true);
    }

    /**
     * @return list<int>
     */
    private function userTeacherIds(User $user): array
    {
        $ids = [];
        foreach (['klassci_id', 'klassci_enseignant_id'] as $key) {
            $value = $user->getAttribute($key);
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    private function studentBelongsToSeanceClass(Seance $seance, User $user): bool
    {
        if (! $user->isStudent()) {
            return false;
        }

        return UserClass::query()
            ->where('institution_id', $seance->institution_id)
            ->where('user_id', $user->id)
            ->where('klassci_classe_id', $seance->klassci_classe_id)
            ->exists();
    }

    /**
     * @return array{id:string,status:string,url:?string,started_at:?string,stopped_at:?string,processed_at:?string,error_message:?string}
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
     * @return array{id:string,status:string,url:?string,started_at:?string,stopped_at:?string,processed_at:?string,error_message:?string}
     */
    private function normalizeState(Seance $seance, array $state): array
    {
        $idle = $this->idleState($seance);

        return [
            'id' => $this->stringValue($state['id'] ?? null, $idle['id']),
            'status' => $this->stringValue($state['status'] ?? null, $idle['status']),
            'url' => $this->stringOrNull($state['url'] ?? null),
            'started_at' => $this->stringOrNull($state['started_at'] ?? null),
            'stopped_at' => $this->stringOrNull($state['stopped_at'] ?? null),
            'processed_at' => $this->stringOrNull($state['processed_at'] ?? null),
            'error_message' => $this->stringOrNull($state['error_message'] ?? null),
        ];
    }

    /**
     * @return array{id:string,status:string,url:?string,started_at:?string,stopped_at:?string,processed_at:?string,error_message:?string}
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
