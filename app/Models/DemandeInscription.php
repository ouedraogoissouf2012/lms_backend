<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EtatDeLaDemande;
use App\Models\Concerns\Auditable;
use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Une demande d'inscription déposée par une famille auprès d'une école autonome (#1659). Seuls les champs utiles
 * (loi 2013-450) ; le consentement est horodaté ; chaque geste de la gestion est tracé (`Auditable`).
 *
 * @property int $id
 * @property int $institution_id
 * @property string $type
 * @property string $nom
 * @property string $prenoms
 * @property Carbon|null $date_naissance
 * @property string|null $genre
 * @property string $declarant_nom
 * @property string $declarant_relation
 * @property string|null $declarant_telephone
 * @property string|null $declarant_courriel
 * @property string|null $classe_souhaitee
 * @property string|null $diplome_entree
 * @property Carbon $consentement_le
 * @property EtatDeLaDemande $etat
 * @property string|null $motif_rejet
 * @property int|null $traite_par
 * @property Carbon|null $traite_le
 * @property int|null $eleve_id
 * @property Carbon|null $created_at
 */
final class DemandeInscription extends Model
{
    use Auditable;
    use BelongsToInstitution;

    public const TYPES = ['nouvelle', 'reinscription'];

    public const RELATIONS = ['pere', 'mere', 'tuteur', 'eleve', 'autre'];

    protected $table = 'demandes_inscription';

    protected $fillable = [
        'institution_id', 'type', 'nom', 'prenoms', 'date_naissance', 'genre',
        'declarant_nom', 'declarant_relation', 'declarant_telephone', 'declarant_courriel',
        'classe_souhaitee', 'diplome_entree', 'consentement_le', 'etat', 'motif_rejet', 'traite_par', 'traite_le', 'eleve_id',
    ];

    protected $casts = [
        'institution_id' => 'integer',
        'date_naissance' => 'date:Y-m-d',
        'consentement_le' => 'datetime',
        'etat' => EtatDeLaDemande::class,
        'traite_par' => 'integer',
        'traite_le' => 'datetime',
        'eleve_id' => 'integer',
    ];
}
