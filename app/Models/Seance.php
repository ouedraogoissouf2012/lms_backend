<?php

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Database\Factories\SeanceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `heure_debut`/`heure_fin` n'existent sur AUCUNE migration (vérifié) ; lues à tort par
 * `FinalizeSeanceAttendances`, qui échoue à chaque exécution planifiée — voir #390 avant `$fillable`.
 */
class Seance extends Model
{
    /** @use HasFactory<SeanceFactory> */
    use BelongsToInstitution, HasFactory, SoftDeletes;

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
        'synced_at',
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
        // #582 — dernière confirmation de la séance par KLASSCI. Sert de critère
        // d'archivage : non confirmée depuis le début du cycle courant = disparue.
        'synced_at' => 'datetime',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $appends = ['current_participants_count'];

    public function getCurrentParticipantsCountAttribute(): int
    {
        $preloaded = $this->attributes['connected_participants_count'] ?? null;
        if ($preloaded !== null) {
            return is_numeric($preloaded) ? (int) $preloaded : 0;
        }

        return $this->attendances()->where('status', 'connected')->count();
    }

    /** @param Builder<Seance> $query
     * @return Builder<Seance> */
    public function scopeWithConnectedParticipantsCount($query)
    {
        return $query->withCount(['attendances as connected_participants_count' => function ($q): void {
            $q->where('status', 'connected');
        }]);
    }

    /** @return HasMany<ESBTPAttendance, $this> */
    public function attendances()
    {
        return $this->hasMany(ESBTPAttendance::class, 'seance_id');
    }

    /** @return HasMany<SeanceRecording, $this> */
    public function recordings(): HasMany
    {
        return $this->hasMany(SeanceRecording::class);
    }

    /** @return HasOne<SeanceRecording, $this> */
    public function latestRecording(): HasOne
    {
        return $this->hasOne(SeanceRecording::class)->latestOfMany();
    }

    /** @param Builder<Seance> $query
     * @return Builder<Seance> */
    public function scopeByTeacher($query, int $teacherId)
    {
        return $query->where('klassci_enseignant_id', $teacherId);
    }

    /** @param Builder<Seance> $query
     * @return Builder<Seance> */
    public function scopeByClasse($query, int $classeId)
    {
        return $query->where('klassci_classe_id', $classeId);
    }

    /** @param Builder<Seance> $query
     * @return Builder<Seance> */
    public function scopeWithVisio($query)
    {
        return $query->where('visio_enabled', true);
    }

    /** @param Builder<Seance> $query
     * @return Builder<Seance> */
    public function scopeByKlassciId($query, int $klassciSeanceId)
    {
        return $query->where('klassci_seance_id', $klassciSeanceId);
    }
}
