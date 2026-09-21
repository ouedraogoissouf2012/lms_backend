<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\BelongsToInstitution;

class EvaluationQuestion extends Model
{
    /** @use HasFactory<\Database\Factories\EvaluationQuestionFactory> */
    use HasFactory, BelongsToInstitution;

    protected $fillable = [
        'evaluation_id',
        'question',
        'type',
        'ordre',
        'points',
        'options',
        'correct_answers',
        'explanation',
        'is_required',
        'institution_id',
    ];

    protected $casts = [
        'points' => 'decimal:2',
        'options' => 'array',
        'correct_answers' => 'array',
        'is_required' => 'boolean',
    ];

    /**
     * Le corrigé ne sort JAMAIS par défaut.
     *
     * ## Pourquoi la garde vit ici, et pas dans les appelants
     *
     * Deux endroits seulement se souvenaient de retirer ces clés : un `unset`
     * dans `EvaluationStudentSubmissionController`, une liste blanche dans
     * `TeacherEvaluationViewService`. Tous les autres chemins les laissaient
     * passer — dont la liste d'évaluations de l'élève, son écran normal, qui
     * livrait les bonnes réponses AVANT l'épreuve.
     *
     * Masquer par défaut renverse la charge : « penser à masquer » devient
     * « penser à montrer ». Un oubli ne fuit plus, il prive — et cela se voit,
     * au lieu de se taire.
     *
     * ## Ce que cela n'empêche pas
     *
     * `$hidden` ne porte que sur la sérialisation (`toArray()` / `toJson()`).
     * La correction automatique lit l'attribut directement
     * (`EvaluationGradingService:58-84`) et n'est donc pas affectée.
     *
     * ## Qui a le droit de les montrer
     *
     * Le seul chemin légitime est la copie de l'élève lui-même, une fois le
     * délai de correction écoulé : il appelle alors `makeVisible()`
     * explicitement. L'enseignant, lui, construit sa propre liste blanche.
     *
     * @var list<string>
     */
    protected $hidden = [
        'correct_answers',
        'explanation',
    ];

    /**
     * Une question appartient à une évaluation
     *
     * @return BelongsTo<Evaluation, $this>
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

}
