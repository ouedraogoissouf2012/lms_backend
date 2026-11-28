<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Une sous-période d'une période de formation (#1806) : un semestre, un trimestre. Elle tient dans les dates de
 * sa période et ne chevauche aucune de ses sœurs (vérifié par `SousPeriodesDeLaPeriode`, sous verrou).
 *
 * @property int $id
 * @property int $training_session_id
 * @property string $libelle
 * @property Carbon $debut
 * @property Carbon $fin
 * @property int|null $ordre
 */
class SousPeriode extends Model
{
    use BelongsToInstitution;

    protected $table = 'sous_periodes';

    protected $fillable = ['institution_id', 'training_session_id', 'libelle', 'debut', 'fin', 'ordre'];

    protected $casts = [
        'debut' => 'date',
        'fin' => 'date',
        'ordre' => 'integer',
    ];
}
