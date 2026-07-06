<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\SeanceRecordingStatus;
use App\Models\SeanceRecording;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

trait HasSeanceRecordings
{
    /**
     * Relation: enregistrements produits pour cette séance.
     *
     * @return HasMany<SeanceRecording, $this>
     */
    public function recordings(): HasMany
    {
        return $this->hasMany(SeanceRecording::class);
    }

    /**
     * Dernier enregistrement connu pour enrichir le payload visio.
     *
     * @return HasOne<SeanceRecording, $this>
     */
    public function latestRecording(): HasOne
    {
        return $this->hasOne(SeanceRecording::class)->latestOfMany();
    }

    /**
     * Enregistrement encore dans un cycle actif (anti double-start).
     *
     * @return HasOne<SeanceRecording, $this>
     */
    public function activeRecording(): HasOne
    {
        return $this->hasOne(SeanceRecording::class)
            ->whereIn('status', SeanceRecordingStatus::activeValues())
            ->latestOfMany();
    }
}
