<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * #1698 (C2) : un élève dispensé d'une évaluation. Sa note, s'il en a une, ne compte nulle part
 * (la règle vit dans `CopiesFinales`) ; sa case du carnet se lit `dispense`.
 *
 * L'élève est désigné comme sur ses copies : par son compte local (`student_id`), sinon, pour
 * un élève du roster KLASSCI sans compte, par son identité KLASSCI.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $evaluation_id
 * @property int|null $student_id
 * @property int|null $klassci_etudiant_id
 * @property int|null $accordee_par
 * @property string|null $motif
 */
class DispenseDEvaluation extends Model
{
    use Auditable, BelongsToInstitution;

    protected $table = 'dispenses_d_evaluation';

    protected $fillable = [
        'institution_id',
        'evaluation_id',
        'student_id',
        'klassci_etudiant_id',
        'accordee_par',
        'motif',
    ];

    protected $casts = [
        'institution_id' => 'integer',
        'evaluation_id' => 'integer',
        'student_id' => 'integer',
        'klassci_etudiant_id' => 'integer',
        'accordee_par' => 'integer',
    ];
}
