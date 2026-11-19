<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\EvaluationSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Les portées de recherche des copies ({@see EvaluationSubmission}) : celles d'un élève, et
 * celles à synchroniser vers KLASSCI.
 *
 * Extraites d'`EvaluationSubmission` telles quelles (#1231) : le reformatage Pint ajoutait des
 * imports, et le modèle dépassait la limite de 150 lignes.
 */
trait EvaluationSubmissionScopes
{
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

    /**
     * Les copies à synchroniser vers KLASSCI (D540) : remises, hors entraînement. Seul lieu du filtre.
     *
     * @param  Builder<EvaluationSubmission>  $query
     * @return Builder<EvaluationSubmission>
     */
    public function scopeASynchroniser(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), ['soumis', 'corrige'])
            ->where(fn (Builder $q) => $q->whereNull($query->qualifyColumn('feedback'))->orWhere($query->qualifyColumn('feedback'), 'NOT LIKE', '[PRACTICE]%'));
    }
}
