<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * L'emploi du temps d'une classe (#1669) : une semaine type ({@see LigneEmploiDuTemps}) sur une plage de dates, dans la
 * période de la classe.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $classe_id
 * @property string $titre
 * @property Carbon $du
 * @property Carbon $au
 * @property bool $en_vigueur
 */
class EmploiDuTemps extends Model
{
    use BelongsToInstitution;

    protected $table = 'emplois_du_temps';

    protected $fillable = ['institution_id', 'classe_id', 'titre', 'du', 'au', 'en_vigueur'];

    protected $casts = [
        'du' => 'date',
        'au' => 'date',
        'en_vigueur' => 'boolean',
    ];

    /** @return BelongsTo<Classe, $this> */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class);
    }

    /** @return HasMany<LigneEmploiDuTemps, $this> */
    public function lignes(): HasMany
    {
        return $this->hasMany(LigneEmploiDuTemps::class, 'emploi_du_temps_id')->orderBy('jour')->orderBy('debut')->orderBy('id');
    }
}
