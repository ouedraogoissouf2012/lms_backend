<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\EcheanceDeLaCopie;
use App\Models\Concerns\EvaluationSubmissionScopes;
use App\Models\Traits\BelongsToInstitution;
use Database\Factories\EvaluationSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationSubmission extends Model
{
    /** @use HasFactory<EvaluationSubmissionFactory> */
    use Auditable, BelongsToInstitution, EcheanceDeLaCopie, EvaluationSubmissionScopes, HasFactory;

    protected $fillable = [
        'evaluation_id',
        'student_id',
        'klassci_etudiant_id',
        'attempt',
        'status',
        'started_at',
        'ends_at',
        'submitted_at',
        'answers',
        'presentation',
        'manual_points',
        'score',
        'note_sur_20',
        'feedback',
        'graded_by',
        'graded_at',
        'synced_to_klassci',
        'synced_at',
        'institution_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ends_at' => 'datetime',
        'submitted_at' => 'datetime',
        'answers' => 'array',
        'presentation' => 'array',
        'manual_points' => 'array',
        'graded_at' => 'datetime',
        'score' => 'decimal:2',
        'note_sur_20' => 'decimal:2',
        'synced_to_klassci' => 'boolean',
        'synced_at' => 'datetime',
    ];

    /**
     * Une soumission appartient à une évaluation
     *
     * @return BelongsTo<Evaluation, $this>
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    /**
     * Une soumission appartient à un étudiant (user local)
     *
     * @return BelongsTo<User, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
