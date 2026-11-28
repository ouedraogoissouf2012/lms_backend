<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * Un créneau de la semaine type d'un enseignant (#1666) : jour ISO (1 = lundi … 7), début et fin (`HH:MM:SS`),
 * disponible ou non. Rattaché à l'id LOCAL du compte, dans les deux volets.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $user_id
 * @property int $jour
 * @property string $debut
 * @property string $fin
 * @property bool $disponible
 */
class DisponibiliteEnseignant extends Model
{
    use BelongsToInstitution;

    protected $table = 'disponibilites_enseignant';

    protected $fillable = ['institution_id', 'user_id', 'jour', 'debut', 'fin', 'disponible'];

    protected $casts = [
        'jour' => 'integer',
        'disponible' => 'boolean',
    ];
}
