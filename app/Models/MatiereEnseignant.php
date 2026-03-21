<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\BelongsToInstitution;

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
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope pour récupérer les assignations actives uniquement
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Récupérer les enseignants assignés à une matière
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
     */
    public static function removeEnseignant($klassciMatiereId, $klassciEnseignantId)
    {
        return self::where('klassci_matiere_id', $klassciMatiereId)
            ->where('klassci_enseignant_id', $klassciEnseignantId)
            ->delete();
    }
}
