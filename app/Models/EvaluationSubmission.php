<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\BelongsToInstitution;

class EvaluationSubmission extends Model
{
    use HasFactory, BelongsToInstitution;

    protected $fillable = [
        'evaluation_id',
        'student_id',
        'klassci_etudiant_id',
        'attempt',
        'status',
        'started_at',
        'submitted_at',
        'answers',
        'score',
        'note_sur_20',
        'feedback',
        'synced_to_klassci',
        'synced_at',
        'institution_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'answers' => 'array',
        'score' => 'decimal:2',
        'note_sur_20' => 'decimal:2',
        'synced_to_klassci' => 'boolean',
        'synced_at' => 'datetime',
    ];

    /**
     * Une soumission appartient à une évaluation
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    /**
     * Une soumission appartient à un étudiant (user local)
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

}
