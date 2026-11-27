<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * Une filière d'une école qui tient son catalogue (#1648). Locale seulement : les filières d'une école
 * KLASSCI restent dans KLASSCI.
 *
 * @property int $id
 * @property string $libelle
 * @property string|null $code
 */
class Filiere extends Model
{
    use BelongsToInstitution;

    protected $table = 'filieres';

    protected $fillable = ['libelle', 'code', 'institution_id'];
}
