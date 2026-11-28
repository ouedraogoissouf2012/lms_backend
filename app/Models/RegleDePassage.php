<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * Les règles de passage d'une école (`classe_id` nul) ou la surcharge d'une classe (#1663). Une valeur nulle hérite :
 * de l'école pour une classe, des valeurs par défaut pour l'école. La moyenne de passage de l'école vit dans
 * `institutions.settings.seuil_reussite`, lue par le service `SeuilDePassage`.
 *
 * @property int $id
 * @property int $institution_id
 * @property int|null $classe_id
 * @property int $classe_cle
 * @property string|null $moyenne_passage
 * @property string|null $moyenne_rattrapage
 * @property int|null $max_matieres_rattrapage
 * @property bool|null $redoublement_autorise
 * @property int|null $max_redoublements
 */
final class RegleDePassage extends Model
{
    use Auditable;
    use BelongsToInstitution;

    /** Les champs d'une règle, dans l'ordre des réponses. */
    public const CHAMPS = ['moyenne_passage', 'moyenne_rattrapage', 'max_matieres_rattrapage', 'redoublement_autorise', 'max_redoublements'];

    protected $table = 'regles_de_passage';

    protected $fillable = [
        'institution_id', 'classe_id', 'classe_cle', 'moyenne_passage', 'moyenne_rattrapage', 'max_matieres_rattrapage', 'redoublement_autorise', 'max_redoublements',
    ];

    protected $casts = [
        'institution_id' => 'integer',
        'classe_id' => 'integer',
        'classe_cle' => 'integer',
        'max_matieres_rattrapage' => 'integer',
        'redoublement_autorise' => 'boolean',
        'max_redoublements' => 'integer',
    ];

    /** La clé d'unicité suit toujours la classe : 0 pour la ligne de l'école (index unique `institution_id, classe_cle`). */
    protected static function booted(): void
    {
        self::saving(static function (self $regle): void {
            $regle->classe_cle = $regle->classe_id ?? 0;
        });
    }
}
