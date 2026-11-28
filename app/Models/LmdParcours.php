<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * Un parcours LMD d'une école qui tient son catalogue (#1654). Il se désactive (`is_active`), il ne
 * se supprime pas.
 *
 * @property int $id
 * @property int $mention_id
 * @property string $libelle
 * @property string|null $code
 * @property bool $is_active
 */
class LmdParcours extends Model
{
    use BelongsToInstitution;

    protected $table = 'lmd_parcours';

    protected $fillable = ['institution_id', 'mention_id', 'libelle', 'code', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /** Le défaut de la colonne, connu dès la création : la réponse ne relit pas la ligne. */
    protected $attributes = ['is_active' => true];
}
