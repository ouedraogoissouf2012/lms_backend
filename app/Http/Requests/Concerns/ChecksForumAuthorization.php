<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;

/**
 * Forum action authorization with strict multi-tenant isolation.
 *
 * ## Why this trait exists
 *
 * The `User::isAdmin()` helper conflates two distinct concepts:
 *
 *   - **Intra-tenant admins** : `admin`, `administrateur`, `superAdmin` —
 *     institution-scoped moderators. Must be confined to their own
 *     `institution_id`.
 *   - **Cross-tenant platform manager** : `supradmin` — bypasses tenant
 *     isolation by design (cf. `EnsureRole::userHasRole()` L107-108).
 *
 * Using `isAdmin()` in a FormRequest's `authorize()` therefore lets a tenant-A
 * admin moderate tenant-B resources (CRITICAL-09 IDOR class). This trait
 * disambiguates and enforces the correct semantics for every Forum endpoint.
 *
 * ## Defense in depth
 *
 * `BelongsToInstitution` global scope already filters most cross-tenant route
 * bindings to 404 in practice, but the scope is skippable
 * (`withoutGlobalScope`, tests using `Sanctum::actingAs` bypass middleware,
 * future code paths). This trait closes the gap at the FormRequest layer so
 * that even when the scope is bypassed, cross-tenant access is rejected.
 *
 * ## Design choice: scalar parameters
 *
 * The method takes `?int` for owner user id and tenant institution id rather
 * than the entity objects themselves. Callers extract those scalars at the
 * call site via `$entity?->user_id`. This keeps the trait fully type-safe for
 * PHPStan (no `object::$property` ambiguity), trivially unit-testable, and
 * decoupled from the concrete Forum models.
 *
 * @see PRODUCTION_STANDARDS.md §1.2 Sécurité Absolue + §1.6 D
 * @see app/Models/Traits/BelongsToInstitution.php (upstream layer)
 * @see app/Http/Middleware/ResolveInstitution.php (upstream layer)
 * @see .claude/specs/forum-idor-cross-tenant/design.md
 */
trait ChecksForumAuthorization
{
    /**
     * Authorize a Forum action with tenant isolation + ownership + moderator role check.
     *
     * Check order (short-circuit):
     *   1. If any of `$ownerUserId`, `$tenantInstitutionId`, `$user` is null → deny.
     *   2. If `$user->role === 'supradmin'` → allow (platform manager bypass).
     *   3. If `$tenantInstitutionId !== $user->institution_id` → deny.
     *   4. If `$ownerUserId === $user->id` → allow (owner intra-tenant).
     *   5. If `$user->role` is in `$moderatorRoles` → allow (moderator intra-tenant).
     *   6. Else → deny.
     *
     * @param int|null  $ownerUserId         `user_id` of the entity whose ownership
     *                                       grants access. For topic actions: the
     *                                       topic's `user_id`. For post actions where
     *                                       the topic owner decides (e.g. markAsSolution):
     *                                       the topic's `user_id`, not the post's.
     * @param int|null  $tenantInstitutionId `institution_id` that defines tenant scope.
     *                                       Usually the same entity as ownership.
     * @param User|null $user                Authenticated user. Null defensively.
     * @param string[]  $moderatorRoles      Roles authorized as moderators within
     *                                       the same tenant. Default covers all
     *                                       institution-admin aliases. Pass
     *                                       `coordinateur` explicitly when the
     *                                       action allows coordinator moderation.
     */
    protected function isForumActionAuthorized(
        ?int $ownerUserId,
        ?int $tenantInstitutionId,
        ?User $user,
        array $moderatorRoles = ['admin', 'administrateur', 'superAdmin'],
    ): bool {
        if ($user === null || $ownerUserId === null || $tenantInstitutionId === null) {
            return false;
        }

        if ($user->role === 'supradmin') {
            return true;
        }

        if ($tenantInstitutionId !== $user->institution_id) {
            return false;
        }

        if ($ownerUserId === $user->id) {
            return true;
        }

        return in_array($user->role, $moderatorRoles, true);
    }
}
