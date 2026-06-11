<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Traits\BelongsToInstitution;

class Seance extends Model
{
    use HasFactory, SoftDeletes, BelongsToInstitution;

    protected $fillable = [
        'klassci_seance_id',
        'klassci_matiere_id',
        'klassci_classe_id',
        'klassci_enseignant_id',
        'enseignant_nom',
        'matiere_nom',
        'classe_nom',
        'titre',
        'date_seance',
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
        'is_active',
        'archived_at',
        'archive_reason',
        'institution_id',
    ];

    protected $casts = [
        'visio_enabled' => 'boolean',
        'visio_active' => 'boolean',
        'visio_started_at' => 'datetime',
        'visio_ended_at' => 'datetime',
        'is_active' => 'boolean',
        'archived_at' => 'datetime',
        'date_seance' => 'datetime',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $appends = ['current_participants_count'];

    /**
     * Compte les participants actuellement connectés.
     *
     * ⚠️ DETTE TRACÉE (§5 « JAMAIS: accessors DB ») : cet accessor `$appends`
     * exécute 1 COUNT par séance sérialisée (N+1 sur les listes). 6 services
     * en dépendent via le contrat JSON (`current_participants_count`).
     * Migration prévue : `withCount(['attendances as current_participants_count'
     * => fn($q) => $q->where('status','connected')])` côté queries + retrait
     * de `$appends` — chantier API dédié (cf. issue GitHub H2-Seance).
     */
    public function getCurrentParticipantsCountAttribute(): int
    {
        return ESBTPAttendance::where('seance_id', $this->id)
            ->where('status', 'connected')
            ->count();
    }

    /**
     * Relation: toutes les participations à cette séance
     */
    public function attendances()
    {
        return $this->hasMany(ESBTPAttendance::class, 'seance_id');
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
