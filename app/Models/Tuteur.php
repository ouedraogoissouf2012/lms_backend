<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Un tuteur (parent ou responsable) d'élève(s) d'une école autonome (#1657). Il se partage entre frères et sœurs de la
 * même école, par `dossier_tuteur` (qui porte la relation). Un tuteur qui n'a plus aucun élève est retiré : donnée
 * devenue inutile (loi 2013-450). Aucun champ libre.
 *
 * @property int $id
 * @property int $institution_id
 * @property string $nom
 * @property string|null $telephone
 * @property string|null $courriel
 * @property string|null $profession
 * @property string|null $adresse
 */
final class Tuteur extends Model
{
    use Auditable;
    use BelongsToInstitution;

    public const RELATIONS = ['pere', 'mere', 'tuteur', 'autre'];

    protected $fillable = ['institution_id', 'nom', 'telephone', 'courriel', 'profession', 'adresse'];

    protected $casts = ['institution_id' => 'integer'];

    /** @return BelongsToMany<DossierEleve, $this> */
    public function dossiers(): BelongsToMany
    {
        return $this->belongsToMany(DossierEleve::class, 'dossier_tuteur', 'tuteur_id', 'dossier_eleve_id')
            ->withPivot('relation')
            ->withTimestamps();
    }
}
