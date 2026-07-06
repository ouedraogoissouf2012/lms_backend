<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SeanceRecordingStatus;
use App\Models\Concerns\ManagesSeanceRecordingLifecycle;
use App\Models\Traits\BelongsToInstitution;
use Database\Factories\SeanceRecordingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int|null $institution_id
 * @property SeanceRecordingStatus $status
 */
class SeanceRecording extends Model
{
    /** @use HasFactory<SeanceRecordingFactory> */
    use BelongsToInstitution, HasFactory, ManagesSeanceRecordingLifecycle, SoftDeletes;

    protected $fillable = [
        'uuid',
        'institution_id',
        'seance_id',
        'lesson_id',
        'chapter_id',
        'provider',
        'external_recording_id',
        'status',
        'recording_url',
        'storage_disk',
        'storage_path',
        'duration_seconds',
        'size_bytes',
        'error_message',
        'metadata',
        'started_by',
        'stopped_by',
        'started_at',
        'stopped_at',
        'processed_at',
        'consent_policy_version',
        'expires_at',
        'active_lock_key',
    ];

    protected $casts = [
        'status' => SeanceRecordingStatus::class,
        'metadata' => 'array',
        'duration_seconds' => 'integer',
        'size_bytes' => 'integer',
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
        'processed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => SeanceRecordingStatus::Idle->value,
    ];

    /** @return BelongsTo<Seance, $this> */
    public function seance(): BelongsTo
    {
        return $this->belongsTo(Seance::class);
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return BelongsTo<Chapter, $this> */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /** @return BelongsTo<User, $this> */
    public function stopper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stopped_by');
    }

    /**
     * @param  Builder<SeanceRecording>  $query
     * @return Builder<SeanceRecording>
     */
    public function scopeForSeance(Builder $query, int $seanceId): Builder
    {
        return $query->where('seance_id', $seanceId);
    }

    /**
     * @param  Builder<SeanceRecording>  $query
     * @return Builder<SeanceRecording>
     */
    public function scopeActiveLifecycle(Builder $query): Builder
    {
        return $query->whereIn('status', SeanceRecordingStatus::activeValues());
    }

    /**
     * @param  Builder<SeanceRecording>  $query
     * @return Builder<SeanceRecording>
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', SeanceRecordingStatus::Ready->value);
    }
}
