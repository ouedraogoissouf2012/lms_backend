<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Le code d'émargement d'une séance (#1680). Le code en clair n'est jamais gardé : `code_hash` en est le HMAC.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $seance_id
 * @property ?int $auteur_id
 * @property string $code_hash
 * @property Carbon $expire_le
 * @property ?Carbon $revoque_le
 * @property ?int $retard_apres_minutes
 * @property int $signatures
 */
class CodeEmargement extends Model
{
    use BelongsToInstitution;

    protected $table = 'codes_emargement';

    protected $fillable = [
        'institution_id',
        'seance_id',
        'auteur_id',
        'code_hash',
        'expire_le',
        'revoque_le',
        'retard_apres_minutes',
        'signatures',
    ];

    protected $casts = [
        'expire_le' => 'datetime',
        'revoque_le' => 'datetime',
        'retard_apres_minutes' => 'integer',
        'signatures' => 'integer',
    ];

    /** @return BelongsTo<Seance, $this> */
    public function seance(): BelongsTo
    {
        return $this->belongsTo(Seance::class);
    }
}
