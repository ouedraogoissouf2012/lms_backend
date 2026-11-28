<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeDEvenement;
use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Un événement du calendrier de l'année d'une école (#1671). Sa portée est l'ensemble de ses classes : aucune classe,
 * toute l'école.
 *
 * @property int $id
 * @property int $institution_id
 * @property TypeDEvenement $type
 * @property string $titre
 * @property Carbon $debut
 * @property Carbon $fin
 */
class EvenementCalendrier extends Model
{
    use BelongsToInstitution;

    protected $table = 'evenements_calendrier';

    protected $fillable = ['institution_id', 'type', 'titre', 'debut', 'fin'];

    protected $casts = [
        'type' => TypeDEvenement::class,
        'debut' => 'date',
        'fin' => 'date',
    ];

    /** @return BelongsToMany<Classe, $this> */
    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(Classe::class, 'evenement_calendrier_classe', 'evenement_calendrier_id', 'classe_id');
    }
}
