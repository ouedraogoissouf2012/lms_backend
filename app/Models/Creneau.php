<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Database\Factories\CreneauFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Créneau — l'occurrence datée sous la Période (#800, ADR-711-01).
 *
 * C'est l'assiette de l'émargement et du décompte horaire : aucun financeur ne
 * rembourse sur une plage de dates, il rembourse des heures-stagiaires
 * rattachées à des créneaux signés.
 *
 * À ne pas confondre avec {@see Seance}, qui est une réunion LIVE. Un créneau
 * peut exister sans visio — un présentiel émarge tout autant.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $training_session_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $lieu
 * @property string|null $salle_virtuelle
 * @property int|null $formateur_id Intervenant externe possible, d'où l'absence de FK.
 */
class Creneau extends Model
{
    /** @use HasFactory<CreneauFactory> */
    use BelongsToInstitution, HasFactory;

    protected $table = 'creneaux';

    protected $fillable = [
        'institution_id',
        'training_session_id',
        'starts_at',
        'ends_at',
        'lieu',
        'salle_virtuelle',
        'formateur_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TrainingSession, $this>
     */
    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }
}
