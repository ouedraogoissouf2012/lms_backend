<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EtatJustificatif;
use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Un justificatif d'absence déposé par un élève (#1681), tranché une fois par la gestion.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $eleve_id
 * @property string $motif
 * @property ?Carbon $du
 * @property ?Carbon $au
 * @property ?string $piece_chemin
 * @property ?string $piece_nom
 * @property ?string $piece_mime
 * @property ?int $piece_taille
 * @property EtatJustificatif $etat
 * @property ?int $decide_par_id
 * @property ?Carbon $decide_le
 * @property ?string $motif_rejet
 * @property ?Carbon $created_at
 */
class JustificatifAbsence extends Model
{
    use BelongsToInstitution;

    protected $table = 'justificatifs_absence';

    protected $fillable = [
        'institution_id',
        'eleve_id',
        'motif',
        'du',
        'au',
        'piece_chemin',
        'piece_nom',
        'piece_mime',
        'piece_taille',
        'etat',
        'decide_par_id',
        'decide_le',
        'motif_rejet',
    ];

    protected $casts = [
        'du' => 'date',
        'au' => 'date',
        'piece_taille' => 'integer',
        'etat' => EtatJustificatif::class,
        'decide_le' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function eleve(): BelongsTo
    {
        return $this->belongsTo(User::class, 'eleve_id');
    }

    /** @return BelongsToMany<Seance, $this> */
    public function seances(): BelongsToMany
    {
        return $this->belongsToMany(Seance::class, 'justificatif_absence_seance', 'justificatif_id', 'seance_id');
    }
}
