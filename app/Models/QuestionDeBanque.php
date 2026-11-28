<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * #1702 (D1) : une question de la banque, avec les champs d'une question d'évaluation. Elle n'est
 * jamais posée telle quelle : le tirage (D3) la COPIE dans l'évaluation à sa publication, et une
 * modification ultérieure de la banque ne change pas une épreuve publiée.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $categorie_id
 * @property string $question
 * @property string $type
 * @property string $points
 * @property list<string>|null $options
 * @property list<string>|null $correct_answers
 * @property string|null $explanation
 */
class QuestionDeBanque extends Model
{
    use Auditable, BelongsToInstitution;

    protected $table = 'questions_de_banque';

    protected $fillable = ['institution_id', 'categorie_id', 'question', 'type', 'points', 'options', 'correct_answers', 'explanation'];

    protected $casts = [
        'institution_id' => 'integer',
        'categorie_id' => 'integer',
        'points' => 'decimal:2',
        'options' => 'array',
        'correct_answers' => 'array',
    ];
}
