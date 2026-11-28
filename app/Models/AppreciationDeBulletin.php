<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Traits\BelongsToInstitution;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * #1677 (B1) : l'appréciation d'un élève pour une période : celle d'une matière (`matiere_id`), par
 * son enseignant, ou la générale et la décision du conseil (`matiere_id` nul), par la gestion.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $classe_id
 * @property int $student_id
 * @property int|null $matiere_id
 * @property string $periode_libelle
 * @property CarbonInterface $periode_du
 * @property CarbonInterface $periode_au
 * @property string|null $texte
 * @property string|null $decision
 * @property int|null $auteur_id
 */
class AppreciationDeBulletin extends Model
{
    use Auditable, BelongsToInstitution;

    protected $table = 'appreciations_de_bulletin';

    protected $fillable = ['institution_id', 'classe_id', 'student_id', 'matiere_id', 'periode_libelle', 'periode_du', 'periode_au', 'texte', 'decision', 'auteur_id'];

    protected $casts = [
        'institution_id' => 'integer',
        'classe_id' => 'integer',
        'student_id' => 'integer',
        'matiere_id' => 'integer',
        'periode_du' => 'date',
        'periode_au' => 'date',
        'auteur_id' => 'integer',
    ];
}
