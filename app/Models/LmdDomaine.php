<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * Un domaine LMD d'une école qui tient son catalogue (#1654). Il se désactive (`actif`), il ne
 * se supprime pas.
 *
 * @property int $id
 * @property string $libelle
 * @property string|null $code
 * @property bool $actif
 */
class LmdDomaine extends Model
{
    use BelongsToInstitution;

    protected $table = 'lmd_domaines';

    protected $fillable = ['institution_id', 'libelle', 'code', 'actif'];

    protected $casts = ['actif' => 'boolean'];
}
