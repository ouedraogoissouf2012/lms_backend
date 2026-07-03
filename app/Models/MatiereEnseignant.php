<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\BelongsToInstitution;

/**
 * Assignation matière ↔ enseignant (IDs KLASSCI, cast `integer`).
 *
 * @property int $klassci_matiere_id
 * @property int $klassci_enseignant_id
 * @property int|null $annee_universitaire_id
 * @property string $status
 * @property int|null $created_by
 */
class MatiereEnseignant extends Model
{
    use BelongsToInstitution;

    protected $table = 'matiere_enseignant';

    protected $fillable = [
        'klassci_matiere_id',
        'klassci_enseignant_id',
        'annee_universitaire_id',
        'status',
        'created_by',
        'institution_id',
    ];

    protected $casts = [
        'klassci_matiere_id' => 'integer',
        'klassci_enseignant_id' => 'integer',
        'annee_universitaire_id' => 'integer',
        'created_by' => 'integer',
    ];

    /**
     * Relation avec l'utilisateur qui a créé l'assignation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this>
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope pour récupérer les assignations actives uniquement
     *
     * @param  \Illuminate\Database\Eloquent\Builder<MatiereEnseignant>  $query
     * @return \Illuminate\Database\Eloquent\Builder<MatiereEnseignant>
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Récupérer les enseignants assignés à une matière
     *
     * @param  int  $klassciMatiereId
     * @param  bool  $activeOnly
     * @return array<mixed> IDs KLASSCI des enseignants (ints en pratique — pluck() non résolu par Larastan ici).
     */
    public static function getEnseignantsForMatiere($klassciMatiereId, $activeOnly = true)
    {
        $query = self::where('klassci_matiere_id', $klassciMatiereId);

        if ($activeOnly) {
            $query->active();
        }

        return $query->pluck('klassci_enseignant_id')->toArray();
    }

    /**
     * Récupérer les matières assignées à un enseignant
     *
     * @param  int  $klassciEnseignantId
     * @param  bool  $activeOnly
     * @return array<mixed> IDs KLASSCI des matières (ints en pratique — pluck() non résolu par Larastan ici).
     */
    public static function getMatieresForEnseignant($klassciEnseignantId, $activeOnly = true)
    {
        $query = self::where('klassci_enseignant_id', $klassciEnseignantId);

        if ($activeOnly) {
            $query->active();
        }

        return $query->pluck('klassci_matiere_id')->toArray();
    }

    /**
     * Assigner un enseignant à une matière
     *
     * @param  int  $klassciMatiereId
     * @param  int  $klassciEnseignantId
     * @param  int|null  $createdBy
     * @return self
     */
    public static function assignEnseignant($klassciMatiereId, $klassciEnseignantId, $createdBy = null)
    {
        return self::updateOrCreate(
            [
                'klassci_matiere_id' => $klassciMatiereId,
                'klassci_enseignant_id' => $klassciEnseignantId,
                'annee_universitaire_id' => null // Pour l'instant, année courante
            ],
            [
                'status' => 'active',
                'created_by' => $createdBy
            ]
        );
    }

    /**
     * Retirer un enseignant d'une matière
     *
     * @param  int  $klassciMatiereId
     * @param  int  $klassciEnseignantId
     * @return mixed Nombre de lignes supprimées (Builder::delete()).
     */
    public static function removeEnseignant($klassciMatiereId, $klassciEnseignantId)
    {
        return self::where('klassci_matiere_id', $klassciMatiereId)
            ->where('klassci_enseignant_id', $klassciEnseignantId)
            ->delete();
    }
}
