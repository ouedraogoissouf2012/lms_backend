<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutDeReclamation;
use App\Models\Concerns\Auditable;
use App\Models\Traits\BelongsToInstitution;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * #1678 : la réclamation d'une note par l'élève, l'avis de l'enseignant, la décision de la gestion.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $evaluation_id
 * @property int $evaluation_submission_id
 * @property int $student_id
 * @property string $motif
 * @property StatutDeReclamation $statut
 * @property string|null $note_contestee
 * @property string|null $avis
 * @property string|null $avis_commentaire
 * @property string|null $note_proposee
 * @property int|null $avis_par
 * @property CarbonInterface|null $avis_le
 * @property string|null $decision
 * @property string|null $note_decidee
 * @property string|null $decision_commentaire
 * @property int|null $decision_par
 * @property CarbonInterface|null $decision_le
 * @property CarbonInterface|null $created_at
 */
class ReclamationDeNote extends Model
{
    use Auditable, BelongsToInstitution;

    protected $table = 'reclamations_de_notes';

    protected $fillable = [
        'institution_id', 'evaluation_id', 'evaluation_submission_id', 'student_id', 'motif', 'statut', 'note_contestee',
        'avis', 'avis_commentaire', 'note_proposee', 'avis_par', 'avis_le',
        'decision', 'note_decidee', 'decision_commentaire', 'decision_par', 'decision_le',
    ];

    protected $casts = [
        'institution_id' => 'integer',
        'evaluation_id' => 'integer',
        'evaluation_submission_id' => 'integer',
        'student_id' => 'integer',
        'statut' => StatutDeReclamation::class,
        'note_contestee' => 'decimal:2',
        'note_proposee' => 'decimal:2',
        'note_decidee' => 'decimal:2',
        'avis_par' => 'integer',
        'decision_par' => 'integer',
        'avis_le' => 'datetime',
        'decision_le' => 'datetime',
    ];

    /** @return BelongsTo<EvaluationSubmission, $this> */
    public function copie(): BelongsTo
    {
        return $this->belongsTo(EvaluationSubmission::class, 'evaluation_submission_id');
    }

    /** @return BelongsTo<Evaluation, $this> */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }
}
