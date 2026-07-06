<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\SeanceRecordingStatus;
use App\Models\SeanceRecording;
use Illuminate\Support\Str;

trait ManagesSeanceRecordingLifecycle
{
    protected static function bootManagesSeanceRecordingLifecycle(): void
    {
        static::creating(function (SeanceRecording $recording): void {
            if (! $recording->uuid) {
                $recording->uuid = (string) Str::uuid();
            }
        });

        static::saving(function (SeanceRecording $recording): void {
            $status = $recording->statusEnum();

            if ($recording->seance_id && $status->keepsActiveLock()) {
                $recording->active_lock_key = self::activeLockKeyForSeance((int) $recording->seance_id);

                return;
            }

            if ($status->isTerminal() || $status === SeanceRecordingStatus::Idle) {
                $recording->active_lock_key = null;
            }
        });
    }

    public static function activeLockKeyForSeance(int $seanceId): string
    {
        return "seance-recording:{$seanceId}";
    }

    public function statusEnum(): SeanceRecordingStatus
    {
        return $this->status;
    }

    /**
     * @return array{id:int|string|null,status:string,url:string|null,started_at:string|null,stopped_at:string|null,processed_at:string|null,error_message:string|null}
     */
    public function toRecordingPayload(): array
    {
        $status = $this->statusEnum();

        return [
            'id' => $this->uuid ?: $this->id,
            'status' => $status->value,
            'url' => $status === SeanceRecordingStatus::Ready ? $this->recording_url : null,
            'started_at' => $this->started_at?->toISOString(),
            'stopped_at' => $this->stopped_at?->toISOString(),
            'processed_at' => $this->processed_at?->toISOString(),
            'error_message' => $this->error_message,
        ];
    }
}
