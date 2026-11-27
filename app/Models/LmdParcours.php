<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * Un parcours LMD d'une école qui tient son catalogue (#1654). Il se désactive (`actif`), il ne
 * se supprime pas.
 *
 * @property int $id
 * @property int $mention_id
 * @property string $libelle
 * @property string|null $code
 * @property bool $actif
 */
class LmdParcours extends Model
{
    use BelongsToInstitution;

    protected $table = 'lmd_parcours';

    protected $fillable = ['institution_id', 'mention_id', 'libelle', 'code', 'actif'];

    protected $casts = ['actif' => 'boolean'];
}
