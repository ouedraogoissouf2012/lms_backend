<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une annonce du personnel à toute une classe (#1650). C'est la trace de l'envoi : qui, quoi, à quelle classe (id
 * LOCAL), à combien d'élèves. Chaque élève en a reçu une notification `annonce` qui la désigne.
 *
 * @property int $id
 * @property int $institution_id
 * @property int|null $classe_id
 * @property int|null $auteur_id
 * @property string $titre
 * @property string $message
 * @property int $destinataires
 */
final class Annonce extends Model
{
    use Auditable;
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'classe_id',
        'auteur_id',
        'titre',
        'message',
        'destinataires',
    ];

    protected $casts = [
        'institution_id' => 'integer',
        'classe_id' => 'integer',
        'auteur_id' => 'integer',
        'destinataires' => 'integer',
    ];

    /** @return BelongsTo<Classe, $this> */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class);
    }

    /** @return BelongsTo<User, $this> */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
