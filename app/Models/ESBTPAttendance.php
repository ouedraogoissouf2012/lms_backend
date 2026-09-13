<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Enums\AttendanceMark;
use App\Enums\AttendanceOrigin;
use App\Models\Traits\BelongsToInstitution;

/**
 * `status` = visio (`connected`/`disconnected`/`kicked`). `mark`/`origin` = pédagogie (#717).
 * Pas interchangeables (#391 : le PDF reste à 0 % sans conversion).
 */
class ESBTPAttendance extends Model
{
    /** @use HasFactory<\Database\Factories\ESBTPAttendanceFactory> */
    use HasFactory, BelongsToInstitution;

    protected $table = 'esbtp_attendance';

    protected $fillable = [
        'seance_id',
        'user_id',
        'klassci_etudiant_id',
        'nom',
        'prenom',
        'email',
        'joined_at',
        'left_at',
        'last_seen_at',
        'duration_minutes',
        'status',
        'ip_address',
        'user_agent',
        'is_validated',
        'is_observer',
        'institution_id',
        'mark',
        'origin',
        'delegated_by_id',
        'delegated_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'duration_minutes' => 'integer',
        'is_validated' => 'boolean',
        'is_observer' => 'boolean',
        'mark' => AttendanceMark::class,
        'origin' => AttendanceOrigin::class,
        'delegated_at' => 'datetime',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Seance, $this> */
    public function seance()
    {
        return $this->belongsTo(\App\Models\Seance::class, 'seance_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this>
     */
    public function delegatedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'delegated_by_id');
    }

    /**
     * Scope pour filtrer par séance
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ESBTPAttendance>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ESBTPAttendance>
     */
    public function scopeForSeance($query, int $seanceId)
    {
        return $query->where('seance_id', $seanceId);
    }

    /**
     * Scope pour filtrer les participations validées
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ESBTPAttendance>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ESBTPAttendance>
     */
    public function scopeValidated($query)
    {
        return $query->where('is_validated', true);
    }

    /**
     * Accessor pour formatter la durée
     */
    public function getFormattedDurationAttribute(): ?string
    {
        if (!$this->duration_minutes) {
            return null;
        }

        $hours = floor($this->duration_minutes / 60);
        $minutes = $this->duration_minutes % 60;

        if ($hours > 0) {
            return "{$hours}h {$minutes}min";
        }

        return "{$minutes}min";
    }

    /**
     * Scope pour filtrer les participants connectés
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ESBTPAttendance>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ESBTPAttendance>
     */
    public function scopeConnected($query)
    {
        return $query->where('status', 'connected');
    }

    /**
     * Scope pour filtrer les participants déconnectés
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ESBTPAttendance>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ESBTPAttendance>
     */
    public function scopeDisconnected($query)
    {
        return $query->where('status', 'disconnected');
    }

}
