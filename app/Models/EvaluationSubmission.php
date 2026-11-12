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
     * ## Pourquoi les deux clés, en OU
     *
     * **La clé par laquelle on RETROUVE une copie doit s'accorder avec celles
     * par lesquelles la base juge si l'on peut en INSÉRER une.** Sinon « je
     * n'en trouve pas » et « il en existe déjà une » se contredisent, et
     * `/start` échoue en 500. Depuis D72 (E2a), deux uniques existent :
     * `eval_sub_unique` sur `klassci_etudiant_id` (les copies KLASSCI) et
     * `eval_sub_student_unique` sur `student_id`. La recherche couvre donc les
     * deux : `student_id`, l'auteur, écrit à chaque création (seul présent dans
     * une école créée depuis le LMS) ; `klassci_etudiant_id` pour une ligne
     * d'avant, que le rattrapage `2026_09_20` a pu laisser sans `student_id`.
     *
     * La branche KLASSCI ne vaut QUE pour une ligne sans auteur
     * (`student_id IS NULL`) : une copie qui a un auteur n'est jamais rendue à
     * un autre élève par l'identité KLASSCI, même si elle la porte (rattrapage
     * mal apparié, écriture incohérente). Revue croisée d'E2a. Une telle ligne
     * bloque encore l'insertion par `eval_sub_unique` sans être vue : `/start`
     * la traite en anomalie, et l'exception remonte.
     *
     * ## Pourquoi jamais de requête avec un identifiant nul
     *
     * Laravel traduit `where($colonne, null)` en `whereNull($colonne)`
     * (`Query/Builder.php:936-937`). Un élève sans identité KLASSCI se verrait
     * alors attribuer toutes les copies sans auteur de son établissement : les
     * deux colonnes sont nullables depuis E2a. Un id ou un établissement nul
     * ne ramène rien, et un `klassci_id` nul saute la branche KLASSCI.
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
     * ## Pourquoi l'établissement en fait partie
     *
     * L'identifiant KLASSCI n'est unique qu'au sein d'un établissement. Sans
     * tenant résolu, le scope global s'ouvre, et le seul identifiant désignerait
     * aussi les copies d'un homonyme d'une autre école. L'établissement est donc
     * filtré ICI, explicitement, et un établissement nul ne ramène rien, comme
     * un identifiant nul.
     *
     * @param  Builder<EvaluationSubmission>  $query
     * @return Builder<EvaluationSubmission>
     */
    public function scopeOwnedBy(Builder $query, User $student): Builder
    {
        [$auteur, $klassci, $etablissement] = [$student->getKey(), $student->klassci_id, $student->institution_id];

        if ($etablissement === null || ! is_int($auteur)) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where($query->qualifyColumn('institution_id'), $etablissement)
            ->where(fn (Builder $q) => $q->where($query->qualifyColumn('student_id'), $auteur)
                ->when($klassci !== null, fn (Builder $k) => $k->orWhere(fn (Builder $davant) => $davant
                    ->whereNull($query->qualifyColumn('student_id'))
                    ->where($query->qualifyColumn('klassci_etudiant_id'), $klassci))));
    }
}
