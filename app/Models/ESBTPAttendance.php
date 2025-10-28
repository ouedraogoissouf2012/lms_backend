<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ESBTPAttendance extends Model
{
    use HasFactory;

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
        'duration_minutes',
        'ip_address',
        'user_agent',
        'is_validated',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'duration_minutes' => 'integer',
        'is_validated' => 'boolean',
    ];

    /**
     * Relation avec la séance
     */
    public function seance()
    {
        return $this->belongsTo(\App\Models\Seance::class, 'seance_id');
    }

    /**
     * Relation avec l'utilisateur
     */
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /**
     * Scope pour filtrer par séance
     */
    public function scopeForSeance($query, int $seanceId)
    {
        return $query->where('seance_id', $seanceId);
    }

    /**
     * Scope pour filtrer les participations validées
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
}
