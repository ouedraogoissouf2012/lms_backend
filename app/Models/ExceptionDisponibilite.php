<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Une exception datée aux disponibilités d'un enseignant (#1666) : sans créneau, la journée entière. Rattachée à l'id
 * LOCAL du compte, dans les deux volets.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $user_id
 * @property Carbon $date
 * @property ?string $debut
 * @property ?string $fin
 * @property bool $disponible
 * @property ?string $motif
 */
class ExceptionDisponibilite extends Model
{
    use BelongsToInstitution;

    protected $table = 'exceptions_disponibilite';

    protected $fillable = ['institution_id', 'user_id', 'date', 'debut', 'fin', 'disponible', 'motif'];

    protected $casts = [
        'date' => 'date',
        'disponible' => 'boolean',
    ];
}
