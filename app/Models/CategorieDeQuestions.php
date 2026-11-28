<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * #1702 (D1) : une catégorie de la banque de questions d'un enseignant, pour une matière (id
 * local, dans les deux volets).
 *
 * @property int $id
 * @property int $institution_id
 * @property int $enseignant_id
 * @property int $matiere_id
 * @property string $libelle
 */
class CategorieDeQuestions extends Model
{
    use Auditable, BelongsToInstitution;

    protected $table = 'categories_de_questions';

    protected $fillable = ['institution_id', 'enseignant_id', 'matiere_id', 'libelle'];

    protected $casts = [
        'institution_id' => 'integer',
        'enseignant_id' => 'integer',
        'matiere_id' => 'integer',
    ];

    /** @return HasMany<QuestionDeBanque, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(QuestionDeBanque::class, 'categorie_id')->orderBy('id');
    }
}
