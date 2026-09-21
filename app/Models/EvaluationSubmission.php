<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\BelongsToInstitution;
use App\Models\Concerns\Auditable;

class EvaluationSubmission extends Model
{
    /** @use HasFactory<\Database\Factories\EvaluationSubmissionFactory> */
    use HasFactory, BelongsToInstitution, Auditable;

    protected $fillable = [
        'evaluation_id',
        'student_id',
        'klassci_etudiant_id',
        'attempt',
        'status',
        'started_at',
        'submitted_at',
        'answers',
        'manual_points',
        'score',
        'note_sur_20',
        'feedback',
        'graded_by',
        'graded_at',
        'synced_to_klassci',
        'synced_at',
        'institution_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'answers' => 'array',
        'manual_points' => 'array',
        'graded_at' => 'datetime',
        'score' => 'decimal:2',
        'note_sur_20' => 'decimal:2',
        'synced_to_klassci' => 'boolean',
        'synced_at' => 'datetime',
    ];

    /**
     * Une soumission appartient à une évaluation
     *
     * @return BelongsTo<Evaluation, $this>
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    /**
     * Une soumission appartient à un étudiant (user local)
     *
     * @return BelongsTo<User, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * Les copies de cet élève — l'UNIQUE définition de la propriété d'une copie.
     *
     * ## Pourquoi `klassci_etudiant_id`, et non la colonne locale
     *
     * Parce que c'est la colonne que l'index unique contraint :
     * `eval_sub_unique (evaluation_id, klassci_etudiant_id, attempt)`
     * (`2025_10_19_181127:42`). **La clé par laquelle on RETROUVE une copie doit
     * être celle par laquelle la base juge si l'on peut en INSÉRER une.** Sinon
     * « je n'en trouve pas » et « il en existe déjà une » se contredisent : on
     * croit devoir créer, la base refuse, et la remise échoue en 500.
     *
     * C'est exactement le défaut d'origine, et une première version de ce
     * correctif l'a reproduit en keyant la propriété sur `student_id` : les
     * copies semées par les tests, qui ne portent que l'identifiant KLASSCI,
     * redevenaient invisibles — et `/start` rejouait la collision.
     *
     * `student_id` est désormais écrit à chaque création, mais comme **miroir
     * local** : il rend la ligne lisible sans jointure et prépare #798. Il ne
     * devient la clé de propriété que le jour où l'index unique le suivra.
     *
     * ## Pourquoi jamais de requête avec un identifiant nul
     *
     * Laravel traduit `where($colonne, null)` en `whereNull($colonne)`
     * (`Query/Builder.php:936-937`). Un élève sans identité KLASSCI se verrait
     * alors attribuer toutes les copies sans propriétaire KLASSCI. La colonne
     * est `NOT NULL`, donc l'ensemble est vide aujourd'hui — mais se reposer
     * là-dessus, c'est faire dépendre une règle de propriété d'un détail de
     * schéma. On refuse explicitement.
     *
     * ## Pourquoi une portée, et pas la même condition recopiée
     *
     * Trois sites désignaient le propriétaire, et deux se trompaient d'espace :
     * `/start` écrivait `klassci_etudiant_id` quand `/submit` et la garde
     * anti-double-soumission lisaient `student_id`, jamais renseigné.
     *
     * « N chemins qui décident chacun, ce sont N politiques qui divergent »
     * (ADR-760-01). Une seule portée, appelée partout, rend la divergence
     * impossible plutôt qu'improbable.
     *
     * @param  Builder<EvaluationSubmission>  $query
     * @return Builder<EvaluationSubmission>
     */
    public function scopeOwnedBy(Builder $query, User $student): Builder
    {
        $proprietaireKlassci = $student->klassci_id;

        if ($proprietaireKlassci === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('klassci_etudiant_id', $proprietaireKlassci);
    }
}
