<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Seance extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'klassci_seance_id',
        'klassci_matiere_id',
        'klassci_classe_id',
        'klassci_enseignant_id',
        'enseignant_nom',
        'matiere_nom',
        'classe_effectif',
        'visio_enabled',
        'visio_type',
        'visio_room_id',
        'visio_status',
        'visio_active',
        'visio_started_at',
        'visio_ended_at',
        'visio_participants_count',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'visio_enabled' => 'boolean',
        'visio_active' => 'boolean',
        'visio_started_at' => 'datetime',
        'visio_ended_at' => 'datetime',
    ];

    /**
     * Vérifie si la visio est activée
     */
    public function hasVisio(): bool
    {
        return $this->visio_enabled;
    }

    /**
     * Vérifie si la visio est actuellement active
     */
    public function isVisioActive(): bool
    {
        return $this->visio_active;
    }

    /**
     * Générer un Room ID unique pour la visio
     */
    public function generateVisioRoomId(): string
    {
        return 'lms_seance_' . $this->id . '_' . time();
    }

    /**
     * Activer la visioconférence
     */
    public function enableVisio(string $type = 'jitsi'): void
    {
        $this->visio_enabled = true;
        $this->visio_type = $type;

        if (!$this->visio_room_id) {
            $this->visio_room_id = $this->generateVisioRoomId();
        }

        $this->save();
    }

    /**
     * Désactiver la visioconférence
     */
    public function disableVisio(): void
    {
        $this->visio_enabled = false;
        $this->visio_type = null;
        $this->visio_active = false;
        $this->visio_started_at = null;
        $this->visio_ended_at = null;
        $this->save();
    }

    /**
     * Démarrer la visioconférence
     */
    public function startVisio(): void
    {
        if (!$this->visio_enabled) {
            throw new \Exception('Visioconférence non programmée pour cette séance');
        }

        $this->visio_active = true;
        $this->visio_started_at = Carbon::now();
        $this->status = 'en_cours';
        $this->save();
    }

    /**
     * Terminer la visioconférence
     */
    public function endVisio(): void
    {
        $this->visio_active = false;
        $this->visio_ended_at = Carbon::now();
        $this->status = 'terminee';
        $this->save();
    }

    /**
     * Scope: Séances d'un enseignant
     */
    public function scopeByTeacher($query, int $teacherId)
    {
        return $query->where('klassci_enseignant_id', $teacherId);
    }

    /**
     * Scope: Séances d'une classe
     */
    public function scopeByClasse($query, int $classeId)
    {
        return $query->where('klassci_classe_id', $classeId);
    }

    /**
     * Scope: Séances avec visio activée
     */
    public function scopeWithVisio($query)
    {
        return $query->where('visio_enabled', true);
    }

    /**
     * Scope: Par ID KLASSCI
     */
    public function scopeByKlassciId($query, int $klassciSeanceId)
    {
        return $query->where('klassci_seance_id', $klassciSeanceId);
    }
}
