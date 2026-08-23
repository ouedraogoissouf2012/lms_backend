<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync\Cursor;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * Flux d'enseignants à synchroniser, repris après une position donnée (#582).
 *
 * ## Pagination par clé (keyset), pas par offset
 *
 * L'ordre `(institution_id, id)` est à la fois le tri ET la clé de reprise :
 * la passe suivante demande « strictement après ce couple ». Contrairement à un
 * `OFFSET`, le coût ne croît pas avec la position, et une insertion concurrente
 * ne décale pas le parcours.
 *
 * ## Colonne de filtre — correction d'un défaut invisible en test
 *
 * Le code d'origine filtrait `whereNotNull('klassci_token')`. Cette colonne a
 * été SUPPRIMÉE par `2026_04_27_000001_encrypt_klassci_tokens` (remplacée par
 * `klassci_token_encrypted`). SQLite réinterprète un identifiant inconnu entre
 * guillemets doubles comme un littéral chaîne — la condition était donc toujours
 * vraie en test — alors que MySQL (production) lève `Unknown column`. Le filtre
 * porte désormais sur la colonne réelle ; `TeacherCursorStreamTest` verrouille
 * ce point sans exiger un MySQL en CI.
 *
 * @see PRODUCTION_STANDARDS.md §1.4 (zéro N+1, requêtes bornées) · §1.6 D
 */
final class TeacherCursorStream
{
    /**
     * Enseignants restants du cycle, en flux paresseux : la population n'est
     * jamais matérialisée en mémoire (R2), même à 200 000 utilisateurs.
     *
     * @return LazyCollection<int, User>
     */
    public function after(SeanceSyncPosition $position): LazyCollection
    {
        return $this->query($position)->cursor();
    }

    /**
     * Reste-t-il des enseignants après cette position ?
     *
     * Sert au cas limite où le budget tombe pile sur le DERNIER enseignant :
     * sans cette vérification (un `EXISTS` indexé), il faudrait une passe
     * entière — 5 minutes — pour seulement constater que le flux est vide, et
     * le dernier tenant attendrait d'autant son archivage.
     */
    public function hasMoreAfter(SeanceSyncPosition $position): bool
    {
        return $this->query($position)->exists();
    }

    /**
     * Exposée pour le test de garde sur les colonnes référencées : c'est la
     * requête réelle, pas une reconstruction.
     *
     * @return Builder<User>
     */
    public function query(SeanceSyncPosition $position): Builder
    {
        // withoutGlobalScope : la sync est cross-tenant PAR CONSTRUCTION. Sans
        // ce retrait explicite, on dépendait du fail-open de BelongsToInstitution
        // (scope inerte hors requête HTTP, doublé d'un WARNING à chaque passe).
        $query = User::withoutGlobalScope('institution')
            ->where('role', Role::Enseignant->value)
            // institution_id est la clé de tenant : un enseignant sans
            // institution ne peut pas être synchronisé de façon isolée (#473).
            // L'exclure ici plutôt qu'en cours de boucle garde le parcours
            // strictement ordonné et les frontières de tenant nettes.
            ->whereNotNull('institution_id')
            ->whereNotNull('klassci_token_encrypted')
            ->orderBy('institution_id')
            ->orderBy('id');

        if ($position->isAtStart()) {
            return $query;
        }

        $institutionId = (int) $position->lastInstitutionId;
        $userId = (int) $position->lastUserId;

        // Comparaison lexicographique du couple : soit un tenant plus loin, soit
        // le même tenant plus loin dans ses enseignants.
        return $query->where(function (Builder $tuple) use ($institutionId, $userId): void {
            $tuple->where('institution_id', '>', $institutionId)
                ->orWhere(function (Builder $sameTenant) use ($institutionId, $userId): void {
                    $sameTenant->where('institution_id', $institutionId)
                        ->where('id', '>', $userId);
                });
        });
    }
}
