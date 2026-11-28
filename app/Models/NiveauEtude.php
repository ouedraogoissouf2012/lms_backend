<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * Un niveau d'études d'une école qui tient son catalogue (#1648). Locale seulement. `ordre` trie la liste
 * (L1 < L2 < L3).
 *
 * @property int $id
 * @property string $libelle
 * @property string|null $code
 * @property int|null $ordre
 */
class NiveauEtude extends Model
{
    use BelongsToInstitution;

    protected $table = 'niveaux_etudes';

    protected $fillable = ['libelle', 'code', 'ordre', 'institution_id'];

    protected $casts = ['ordre' => 'integer'];
}
