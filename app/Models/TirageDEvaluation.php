<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * #1702 (D3) : une règle de tirage — l'évaluation tire `nombre` questions de la catégorie de
 * banque `categorie_id`. À la publication, la catégorie est COPIÉE dans l'évaluation (le vivier,
 * `evaluation_questions.tirage_id`) ; au démarrage de chaque tentative, `nombre` questions du
 * vivier sont tirées et figées sur la copie.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $evaluation_id
 * @property int|null $categorie_id
 * @property int $nombre
 */
class TirageDEvaluation extends Model
{
    use Auditable, BelongsToInstitution;

    protected $table = 'tirages_d_evaluation';

    protected $fillable = ['institution_id', 'evaluation_id', 'categorie_id', 'nombre'];

    protected $casts = [
        'institution_id' => 'integer',
        'evaluation_id' => 'integer',
        'categorie_id' => 'integer',
        'nombre' => 'integer',
    ];

    /** @return BelongsTo<CategorieDeQuestions, $this> */
    public function categorie(): BelongsTo
    {
        return $this->belongsTo(CategorieDeQuestions::class, 'categorie_id');
    }
}
