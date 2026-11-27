<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * Une mention LMD d'une école qui tient son catalogue (#1654). Elle se désactive (`actif`), elle ne
 * se supprime pas.
 *
 * @property int $id
 * @property int $domaine_id
 * @property string $libelle
 * @property string|null $code
 * @property bool $actif
 */
class LmdMention extends Model
{
    use BelongsToInstitution;

    protected $table = 'lmd_mentions';

    protected $fillable = ['institution_id', 'domaine_id', 'libelle', 'code', 'actif'];

    protected $casts = ['actif' => 'boolean'];
}
