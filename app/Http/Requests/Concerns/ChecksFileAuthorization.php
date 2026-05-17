<?php

namespace App\Http\Requests\Concerns;

use App\Models\File;
use App\Models\User;

/**
 * File action authorization with strict multi-tenant isolation.
 *
 * ## Why this trait exists (vs reusing ChecksForumAuthorization from PR #95)
 *
 * Files have an `is_public` flag that grants read access intra-tenant. The
 * Forum trait has no equivalent — adding a flag would force a leaky parameter
 * on Forum FormRequests where it has no meaning. Two file-specific methods
 * (`canReadFile` with public bypass, `canModerateFile` without) keep the
 * semantics local to Files.
 *
 * ## Defense in depth
 *
 * Same strategy as `ChecksForumAuthorization`:
 *   1. null check → deny
 *   2. supradmin role → allow (platform manager bypass, cf. EnsureRole.php L107)
 *   3. institution_id mismatch → deny (tenant isolation)
 *   4. read-specific: is_public OR owner OR admin → allow
 *      moderate-specific: owner OR admin → allow (no is_public bypass)
 *   5. else → deny
 *
 * Without this trait, `User::isAdmin()` would conflate intra-tenant admins
 * with `supradmin` (cross-tenant), letting an admin of institution A read
 * or moderate files of institution B (HIGH IDOR — issue #102).
 *
 * @see PRODUCTION_STANDARDS.md §1.2 Sécurité Absolue + §1.6 D
 * @see app/Http/Requests/Concerns/ChecksForumAuthorization.php (sibling trait)
 * @see app/Models/Traits/BelongsToInstitution.php (upstream global scope)
 * @see .claude/specs/file-idor-cross-tenant/design.md §2
 */
trait ChecksFileAuthorization
{
    /**
     * Authorize READ access to a file (show, download).
     *
     * Check order (short-circuit):
     *   1. null check → deny
     *   2. supradmin → allow (cross-tenant)
     *   3. institution_id mismatch → deny
     *   4. file.is_public → allow (intra-tenant only, already past step 3)
     *   5. file.user_id === user.id → allow (owner)
     *   6. user.role ∈ admin/administrateur/superAdmin → allow (admin intra)
     *   7. else → deny
     */
    protected function canReadFile(?File $file, ?User $user): bool
    {
        if ($file === null || $user === null) {
            return false;
        }

        if ($user->role === 'supradmin') {
            return true;
        }

        if ($file->institution_id !== $user->institution_id) {
            return false;
        }

        if ($file->is_public === true) {
            return true;
        }

        if ($file->user_id === $user->id) {
            return true;
        }

        return in_array($user->role, ['admin', 'administrateur', 'superAdmin'], true);
    }

    /**
     * Authorize MODERATE access to a file (update metadata, delete).
     *
     * Same as canReadFile() but **without** is_public bypass: a public file
     * is not modifiable/deletable by a non-owner non-admin caller.
     */
    protected function canModerateFile(?File $file, ?User $user): bool
    {
        if ($file === null || $user === null) {
            return false;
        }

        if ($user->role === 'supradmin') {
            return true;
        }

        if ($file->institution_id !== $user->institution_id) {
            return false;
        }

        if ($file->user_id === $user->id) {
            return true;
        }

        return in_array($user->role, ['admin', 'administrateur', 'superAdmin'], true);
    }
}
