<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne de la semaine type (#1669) : un COURS (matière du programme, enseignant, type) ou une PAUSE (libellé, ni
 * matière ni enseignant). `jour` suit l'ISO : 1 = lundi … 7 = dimanche ; `debut` et `fin` en `HH:MM:SS`.
 *
 * @property int $id
 * @property int $emploi_du_temps_id
 * @property int $jour
 * @property string $debut
 * @property string $fin
 * @property ?int $matiere_id
 * @property ?int $enseignant_id
 * @property ?string $type_seance
 * @property ?string $libelle
 */
class LigneEmploiDuTemps extends Model
{
    protected $table = 'lignes_emploi_du_temps';

    protected $fillable = ['emploi_du_temps_id', 'jour', 'debut', 'fin', 'matiere_id', 'enseignant_id', 'type_seance', 'libelle'];

    protected $casts = [
        'jour' => 'integer',
        'matiere_id' => 'integer',
        'enseignant_id' => 'integer',
    ];

    public function estUnCours(): bool
    {
        return $this->matiere_id !== null;
    }

    /** @return BelongsTo<EmploiDuTemps, $this> */
    public function emploi(): BelongsTo
    {
        return $this->belongsTo(EmploiDuTemps::class, 'emploi_du_temps_id');
    }

    /** @return BelongsTo<Matiere, $this> */
    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }
}
