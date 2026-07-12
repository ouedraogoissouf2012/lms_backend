<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SeanceRecordingStatus;
use Database\Factories\SeanceRecordingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SeanceRecording extends Model
{
    /** @use HasFactory<SeanceRecordingFactory> */
    use HasFactory;

    private const CONSENT_MESSAGE = 'Cette seance peut etre enregistree. En restant dans la visio, vous acceptez que votre participation soit captee selon les regles de votre etablissement.';

    protected $fillable = [
        'seance_id',
        'lesson_id',
        'chapter_id',
        'provider',
        'provider_recording_id',
        'status',
        'recording_url',
        'title',
        'file_size_bytes',
        'checksum',
        'error_message',
        'started_at',
        'stopped_at',
        'processed_at',
        'active_lock_key',
    ];

    protected $casts = [
        'status' => SeanceRecordingStatus::class,
        'file_size_bytes' => 'integer',
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::saving(function (self $recording): void {
            $status = $recording->status;
            $recording->active_lock_key = $status->isActive()
                ? self::activeLockKeyForSeance((int) $recording->seance_id)
                : null;
        });
    }

    public static function activeLockKeyForSeance(int $seanceId): string
    {
        return 'seance:'.$seanceId;
    }

    /**
     * @return BelongsTo<Seance, $this>
     */
    public function seance(): BelongsTo
    {
        return $this->belongsTo(Seance::class);
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return BelongsTo<Chapter, $this>
     */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /**
     * @return array{id:string,status:string,url:?string,started_at:?string,stopped_at:?string,processed_at:?string,error_message:?string,is_recording:bool,consent_required:bool,consent_message:string,retention_days:int,can_download:bool}
     */
    public function toRecordingPayload(): array
    {
        $status = $this->status;

        return [
            'id' => $this->payloadId(),
            'status' => $status->value,
            'url' => $this->recording_url,
            'started_at' => $this->started_at?->toIso8601String(),
            'stopped_at' => $this->stopped_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'error_message' => $this->error_message,
            'is_recording' => $status === SeanceRecordingStatus::Recording,
            'consent_required' => $status->isActive(),
            'consent_message' => self::CONSENT_MESSAGE,
            'retention_days' => 365,
            'can_download' => false,
        ];
    }

    private function payloadId(): string
    {
        $key = $this->getKey();

        if (is_int($key) || is_string($key)) {
            return (string) $key;
        }

        return '';
    }
}
