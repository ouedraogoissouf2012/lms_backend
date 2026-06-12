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
     * Compte les participants actuellement connectés (#224).
     *
     * Optimisé : si une query parente a préchargé le compteur via
     * `withConnectedParticipantsCount()` (scope ci-dessous), l'attribut
     * `connected_participants_count` est déjà présent → 0 requête. Sinon
     * (accès unitaire à une séance isolée), fallback en 1 COUNT.
     *
     * Le champ JSON `current_participants_count` (contrat frontend) et
     * `$appends` sont conservés : aucune régression côté API.
     */
    public function getCurrentParticipantsCountAttribute(): int
    {
        $preloaded = $this->attributes['connected_participants_count'] ?? null;
        if ($preloaded !== null) {
            return (int) $preloaded;
        }

        return $this->attendances()->where('status', 'connected')->count();
    }

    /**
     * Scope: précharge le compteur de participants connectés en 1 sous-requête
     * (élimine le N+1 de l'accessor sur les listes — #224).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Seance>  $query
     */
    public function scopeWithConnectedParticipantsCount($query)
    {
        return $query->withCount(['attendances as connected_participants_count' => function ($q): void {
            $q->where('status', 'connected');
        }]);
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
