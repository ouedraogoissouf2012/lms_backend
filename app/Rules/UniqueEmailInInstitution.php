<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * L'email est-il libre DANS l'institution concernée ? (issue #580, P1 de #563)
 *
 * ## Faille corrigée
 *
 * {@see \App\Http\Requests\CreateUserRequest} et {@see \App\Http\Requests\UpdateUserRequest}
 * validaient l'email avec `unique:users,email`. Cette règle passe par le
 * `DatabasePresenceVerifier`, qui construit un `DB::table('users')` — query builder BRUT :
 * ni le global scope `institution` ({@see \App\Models\Traits\BelongsToInstitution}) ni le
 * `SoftDeletingScope` ne s'y appliquent. La contrainte était donc GLOBALE à la plateforme,
 * alors que le schéma dit l'inverse : `2026_03_23_121616` supprime l'unicité globale et
 * `2026_03_23_200000` la remplace par l'index `users_email_institution_unique (email, institution_id)`.
 *
 * Double conséquence corrigée :
 *   1. **fonctionnelle** — un vacataire intervenant dans deux écoles ne pouvait pas être créé
 *      dans la seconde, alors que le sync KLASSCI, lui, y parvient (son garde
 *      {@see \App\Services\Klassci\Auth\KlassciEmailConflictGuard} est scopé institution) ;
 *   2. **confidentialité** — « Cet email est déjà utilisé » révélait indirectement l'existence
 *      d'un compte d'un autre tenant à un administrateur sans droit dessus (oracle
 *      d'énumération).
 *
 * ## Invariant : cette règle est l'IMAGE de l'index, angles morts compris
 *
 * Toute divergence entre validation et index produit un défaut : plus stricte → refus
 * illégitime (le bug ci-dessus) ; plus permissive → `INSERT` en violation de contrainte, donc
 * `500`. D'où deux choix qui peuvent surprendre :
 *
 *   - **les lignes soft-deleted comptent** (`withTrashed()`). Ni MySQL ni SQLite n'ont d'index
 *     unique partiel : une ligne supprimée occupe toujours son emplacement dans l'index
 *     (documenté par la migration `2026_08_15_120000` de #566). Les exclure ferait passer la
 *     validation puis échouer l'écriture. La recréation légitime passe par une RESTAURATION —
 *     c'est déjà le modèle de {@see \App\Services\Klassci\Auth\KlassciUserSynchronizer}
 *     (`findExistingUser` + `restoreIfTrashed`). Le message le dit explicitement au lieu de
 *     laisser l'administrateur face à un compte fantôme ;
 *   - **fail-closed sans institution cible**. `Rule::unique()->where('institution_id', null)`
 *     dégénérerait en `WHERE institution_id IS NULL` : on validerait contre un ensemble que
 *     l'index ne contraint PAS (deux `NULL` ne sont jamais égaux en SQL), sans aucun filet base.
 *     On refuse, comme {@see AssignableRole} refuse un acteur non résoluble.
 *
 * ## Quelle institution ?
 *
 * Elle diffère selon l'opération, d'où les deux constructeurs nommés :
 *
 *   - **création** : celle de l'acteur. `BelongsToInstitution::creating` écrira le tenant
 *     courant, et `ResolveInstitution:116` dérive ce tenant de `$token->tokenable->institution_id` —
 *     les deux valeurs sont donc identiques sur tout chemin authentifié. On lit l'identité du
 *     porteur plutôt que le `TenantManager` pour ne pas faire dépendre une règle de validation
 *     d'un ordre d'exécution de middlewares.
 *   - **mise à jour** : celle de la CIBLE. `institution_id` n'est pas modifiable par
 *     `PUT /users/{user}` ; et si l'acteur est un `supradmin` plateforme éditant un compte de
 *     l'école B, scoper sur l'acteur interrogerait le mauvais ensemble.
 *
 * @see .claude/specs/580-email-unique-per-institution/design.md
 * @see tests/Unit/Rules/UniqueEmailInInstitutionTest.php
 */
final class UniqueEmailInInstitution implements ValidationRule
{
    private function __construct(
        private readonly ?int $institutionId,
        private readonly ?int $ignoreUserId,
    ) {
    }

    /** Création : l'email doit être libre dans l'institution de l'acteur. */
    public static function forCreationBy(?User $actor): self
    {
        return new self($actor?->institution_id, null);
    }

    /** Mise à jour : l'email doit être libre dans l'institution de la cible, sa propre ligne exclue. */
    public static function forUpdateOf(?User $target): self
    {
        return new self($target?->institution_id, $target?->id);
    }

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Le format relève de la règle `email` : ne pas produire deux messages pour un défaut.
        if (! is_string($value) || $value === '') {
            return;
        }

        if ($this->institutionId === null) {
            $fail("L'établissement cible n'est pas déterminé : impossible de valider l'unicité de l'email.");

            return;
        }

        $conflict = $this->findConflict($value);

        if ($conflict === null) {
            return;
        }

        // Le message « supprimé » énonce un FAIT (l'emplacement reste occupé) sans promettre
        // d'action : aucune route de restauration n'existe côté administration à ce jour — la
        // seule restauration est la re-synchronisation KLASSCI du titulaire.
        $fail($conflict->trashed()
            ? 'Cet email appartient à un compte supprimé de cet établissement : il reste occupé tant que ce compte n\'a pas été restauré.'
            : 'Cet email est déjà utilisé dans cet établissement.');
    }

    /**
     * Lookup unique sur `users_email_institution_unique`, hors scope tenant (l'institution est
     * portée explicitement) et corbeille incluse (cf. invariant du docblock de classe). Même
     * forme que {@see \App\Services\Klassci\Auth\KlassciEmailConflictGuard::assertNotOwnedByAnother}.
     *
     * Projection volontairement réduite à `id, deleted_at` : on n'a besoin que de l'existence et
     * de l'état corbeille — inutile d'hydrater le nom et l'email d'un tiers dans un chemin de
     * validation.
     */
    private function findConflict(string $email): ?User
    {
        $query = User::withoutGlobalScope('institution')
            ->withTrashed()
            ->where('email', $email)
            ->where('institution_id', $this->institutionId);

        if ($this->ignoreUserId !== null) {
            $query->where('id', '!=', $this->ignoreUserId);
        }

        return $query->first(['id', 'deleted_at']);
    }
}
