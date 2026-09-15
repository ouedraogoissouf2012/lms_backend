<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SchoolRequestStatus;
use Database\Factories\SchoolRegistrationRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Demande d'ouverture d'une école indépendante de KLASSCI (#803).
 *
 * N'utilise PAS le trait `BelongsToInstitution` : une demande n'appartient à
 * aucun tenant — l'établissement n'existe pas encore, et la table est
 * délibérément absente de `config/tenancy.php`. Le champ `institution_id` n'est
 * pas une appartenance mais un RÉSULTAT, rempli à la validation.
 *
 * @property int $id
 * @property string $nom_demandeur
 * @property string $email_demandeur
 * @property string|null $telephone_demandeur
 * @property string $nom_ecole
 * @property string|null $slug_souhaite
 * @property string $usage_prevu
 * @property SchoolRequestStatus $statut Cast d'enum : jamais une chaîne nue côté PHP.
 * @property int|null $decide_par_user_id
 * @property Carbon|null $decide_le
 * @property string|null $motif_refus
 * @property int|null $institution_id Résultat de la validation, pas une appartenance.
 * @property-read Institution|null $institution Créée lors de la validation (ADR-803-02).
 * @property-read User|null $decideur Supradmin plateforme ayant tranché.
 *
 * @see docs/adr/2026-09-15-803-01-demande-publique.md
 */
class SchoolRegistrationRequest extends Model
{
    /** @use HasFactory<SchoolRegistrationRequestFactory> */
    use HasFactory;

    /**
     * `statut`, `institution_id`, `decide_*` et `motif_refus` sont ABSENTS :
     * ils ne sont jamais renseignés depuis une charge utile client. Le statut
     * est posé par le défaut SQL à la création, et par le service de validation
     * ensuite. C'est le seul verrou qui garantit qu'un inconnu ne s'auto-valide
     * pas en postant `statut=validee`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'nom_demandeur',
        'email_demandeur',
        'telephone_demandeur',
        'nom_ecole',
        'slug_souhaite',
        'usage_prevu',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'statut' => SchoolRequestStatus::class,
            'decide_le' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decide_par_user_id');
    }
}
