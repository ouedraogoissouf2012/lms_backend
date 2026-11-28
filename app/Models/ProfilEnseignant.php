<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GradeAcademique;
use App\Enums\StatutEnseignant;
use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Le profil d'un enseignant d'une école créée depuis le LMS (#1666), rattaché à l'id LOCAL du compte.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $user_id
 * @property ?string $matricule
 * @property ?string $specialite
 * @property ?string $titre
 * @property ?GradeAcademique $grade
 * @property ?string $diplome
 * @property ?string $universite
 * @property ?Carbon $date_debut
 * @property ?StatutEnseignant $statut
 * @property ?int $charge_hebdo_max
 */
class ProfilEnseignant extends Model
{
    use BelongsToInstitution;

    protected $table = 'profils_enseignant';

    protected $fillable = [
        'institution_id', 'user_id', 'matricule', 'specialite', 'titre', 'grade', 'diplome', 'universite',
        'date_debut', 'statut', 'charge_hebdo_max',
    ];

    protected $casts = [
        'grade' => GradeAcademique::class,
        'statut' => StatutEnseignant::class,
        'date_debut' => 'date',
        'charge_hebdo_max' => 'integer',
    ];
}
