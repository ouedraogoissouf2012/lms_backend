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
        'last_seen_at',
        'duration_minutes',
        'status',
        'ip_address',
        'user_agent',
        'is_validated',
        'is_observer',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'duration_minutes' => 'integer',
        'is_validated' => 'boolean',
        'is_observer' => 'boolean',
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

    /**
     * Scope pour filtrer les participants connectés
     */
    public function scopeConnected($query)
    {
        return $query->where('status', 'connected');
    }

    /**
     * Scope pour filtrer les participants déconnectés
     */
    public function scopeDisconnected($query)
    {
        return $query->where('status', 'disconnected');
    }

    /**
     * Vérifier si le participant est actuellement connecté
     */
    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    /**
     * Marquer comme déconnecté
     */
    public function markAsDisconnected(): void
    {
        $this->status = 'disconnected';
        $this->left_at = now();

        // Calculer la durée si joined_at existe
        if ($this->joined_at) {
            $this->duration_minutes = $this->joined_at->diffInMinutes($this->left_at);
        }

        $this->save();
    }

    /**
     * Mettre à jour le heartbeat
     */
    public function updateHeartbeat(): void
    {
        $this->last_seen_at = now();
        $this->save();
    }

    /**
     * Déconnecter automatiquement les participants inactifs (timeout)
     * Un participant est considéré inactif si son dernier heartbeat date de plus de X minutes
     * 
     * @param int $timeoutMinutes Nombre de minutes d'inactivité avant timeout (défaut: 3)
     * @return int Nombre de participants déconnectés
     */
    public static function disconnectInactiveParticipants(int $timeoutMinutes = 3): int
    {
        $timeoutThreshold = now()->subMinutes($timeoutMinutes);
        
        // Trouver tous les participants connectés dont le dernier heartbeat est trop ancien
        $inactiveParticipants = self::where('status', 'connected')
            ->where(function($query) use ($timeoutThreshold) {
                // Soit last_seen_at est trop ancien
                $query->where('last_seen_at', '<', $timeoutThreshold)
                      // Soit last_seen_at est NULL mais joined_at est trop ancien (fallback)
                      ->orWhere(function($q) use ($timeoutThreshold) {
                          $q->whereNull('last_seen_at')
                            ->where('joined_at', '<', $timeoutThreshold);
                      });
            })
            ->get();
        
        $disconnectedCount = 0;
        
        foreach ($inactiveParticipants as $participant) {
            \Log::info('Timeout détecté - Déconnexion automatique', [
                'user_id' => $participant->user_id,
                'seance_id' => $participant->seance_id,
                'last_seen_at' => $participant->last_seen_at?->toDateTimeString(),
                'joined_at' => $participant->joined_at?->toDateTimeString(),
            ]);
            
            $participant->markAsDisconnected();
            $disconnectedCount++;
        }
        
        return $disconnectedCount;
    }

}
