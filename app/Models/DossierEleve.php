<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutDuDossierEleve;
use App\Models\Concerns\Auditable;
use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Le dossier d'un élève d'une école autonome (#1657) : identité et matricule, un par compte élève. Il part avec le compte
 * purgé. Seuls les champs utiles (loi 2013-450) ; chaque écriture est tracée (`Auditable`).
 *
 * @property int $id
 * @property int $institution_id
 * @property int $user_id
 * @property string $matricule
 * @property string $nom
 * @property string $prenoms
 * @property string|null $genre
 * @property Carbon|null $date_naissance
 * @property string|null $lieu_naissance
 * @property string|null $nationalite
 * @property string|null $adresse
 * @property string|null $ville
 * @property string|null $commune
 * @property StatutDuDossierEleve $statut
 * @property string|null $photo_chemin
 */
final class DossierEleve extends Model
{
    use Auditable;
    use BelongsToInstitution;

    public const GENRES = ['F', 'M'];

    protected $table = 'dossiers_eleves';

    protected $fillable = [
        'institution_id', 'user_id', 'matricule', 'nom', 'prenoms', 'genre', 'date_naissance',
        'lieu_naissance', 'nationalite', 'adresse', 'ville', 'commune', 'statut', 'photo_chemin',
    ];

    protected $casts = [
        'institution_id' => 'integer',
        'user_id' => 'integer',
        'date_naissance' => 'date:Y-m-d',
        'statut' => StatutDuDossierEleve::class,
    ];

    /** @return BelongsTo<User, $this> */
    public function eleve(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsToMany<Tuteur, $this> */
    public function tuteurs(): BelongsToMany
    {
        return $this->belongsToMany(Tuteur::class, 'dossier_tuteur', 'dossier_eleve_id', 'tuteur_id')
            ->withPivot('relation')
            ->withTimestamps()
            ->orderBy('tuteurs.id');
    }
}
