<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Concerns;

use App\Http\Requests\Concerns\ChecksForumAuthorization;
use App\Models\User;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for {@see ChecksForumAuthorization}.
 *
 * The trait exposes a single protected method `isForumActionAuthorized()`
 * that returns a boolean from 5 inputs (owner id, tenant id, user, moderator
 * roles). The 6-step decision tree is documented in the trait's PHPDoc.
 *
 * A throwaway concrete subclass is declared to expose the protected method.
 *
 * Reference: .claude/specs/forum-idor-cross-tenant/design.md §3.1
 */
#[CoversTrait(ChecksForumAuthorization::class)]
final class ChecksForumAuthorizationTest extends TestCase
{
    private function subject(): object
    {
        return new class () {
            use ChecksForumAuthorization {
                isForumActionAuthorized as public;
            }
        };
    }

    private function user(int $id, ?int $institutionId, string $role): User
    {
        return (new User())->forceFill([
            'id' => $id,
            'institution_id' => $institutionId,
            'role' => $role,
        ]);
    }

    /**
     * T1 — Null inputs always deny.
     */
    public function test_deny_when_all_inputs_null(): void
    {
        self::assertFalse(
            $this->subject()->isForumActionAuthorized(null, null, null),
        );
    }

    /**
     * T2 — Null user denies even with valid entity scalars.
     */
    public function test_deny_when_user_null(): void
    {
        self::assertFalse(
            $this->subject()->isForumActionAuthorized(
                ownerUserId: 1,
                tenantInstitutionId: 1,
                user: null,
            ),
        );
    }

    /**
     * T2b — Null owner denies even with valid user and tenant.
     */
    public function test_deny_when_owner_null(): void
    {
        self::assertFalse(
            $this->subject()->isForumActionAuthorized(
                ownerUserId: null,
                tenantInstitutionId: 1,
                user: $this->user(id: 5, institutionId: 1, role: 'admin'),
            ),
        );
    }

    /**
     * T2c — Null tenant denies even with valid user and owner.
     */
    public function test_deny_when_tenant_null(): void
    {
        self::assertFalse(
            $this->subject()->isForumActionAuthorized(
                ownerUserId: 5,
                tenantInstitutionId: null,
                user: $this->user(id: 5, institutionId: 1, role: 'admin'),
            ),
        );
    }

    /**
     * T3 — Supradmin bypasses tenant isolation entirely.
     */
    public function test_allow_supradmin_cross_tenant(): void
    {
        self::assertTrue(
            $this->subject()->isForumActionAuthorized(
                ownerUserId: 999,
                tenantInstitutionId: 7,
                user: $this->user(id: 1, institutionId: 1, role: 'supradmin'),
            ),
        );
    }

    /**
     * T4 — Owner intra-tenant is allowed.
     */
    public function test_allow_owner_intra_tenant(): void
    {
        self::assertTrue(
            $this->subject()->isForumActionAuthorized(
                ownerUserId: 42,
                tenantInstitutionId: 3,
                user: $this->user(id: 42, institutionId: 3, role: 'etudiant'),
            ),
        );
    }

    /**
     * T5 — Owner cross-tenant is denied (institution_id mismatch wins).
     */
    public function test_deny_owner_cross_tenant(): void
    {
        self::assertFalse(
            $this->subject()->isForumActionAuthorized(
                ownerUserId: 42,
                tenantInstitutionId: 99,
                user: $this->user(id: 42, institutionId: 3, role: 'etudiant'),
            ),
        );
    }

    /**
     * T6 — Admin intra-tenant is allowed via default moderatorRoles.
     */
    public function test_allow_admin_intra_tenant(): void
    {
        self::assertTrue(
            $this->subject()->isForumActionAuthorized(
                ownerUserId: 99,
                tenantInstitutionId: 3,
                user: $this->user(id: 5, institutionId: 3, role: 'admin'),
            ),
        );
    }

    /**
     * T7 — Admin cross-tenant is denied (this is the CRITICAL-09 bug fix).
     */
    public function test_deny_admin_cross_tenant(): void
    {
        self::assertFalse(
            $this->subject()->isForumActionAuthorized(
                ownerUserId: 99,
                tenantInstitutionId: 7,
                user: $this->user(id: 5, institutionId: 3, role: 'admin'),
            ),
        );
    }

    /**
     * T8 — Coordinateur is NOT in default moderator roles (only when explicitly added).
     */
    public function test_deny_coordinateur_with_default_moderator_roles(): void
    {
        self::assertFalse(
            $this->subject()->isForumActionAuthorized(
                ownerUserId: 99,
                tenantInstitutionId: 3,
                user: $this->user(id: 5, institutionId: 3, role: 'coordinateur'),
            ),
        );
    }

    /**
     * T9 — Coordinateur intra-tenant is allowed when passed in moderatorRoles.
     */
    public function test_allow_coordinateur_intra_tenant_when_in_moderator_roles(): void
    {
        self::assertTrue(
            $this->subject()->isForumActionAuthorized(
                ownerUserId: 99,
                tenantInstitutionId: 3,
                user: $this->user(id: 5, institutionId: 3, role: 'coordinateur'),
                moderatorRoles: ['admin', 'administrateur', 'superAdmin', 'coordinateur'],
            ),
        );
    }

    /**
     * T10 — Student denied even with coordinateur added to moderatorRoles
     *       (student is not in the moderator list).
     */
    public function test_deny_etudiant_with_coordinateur_in_moderator_roles(): void
    {
        self::assertFalse(
            $this->subject()->isForumActionAuthorized(
                ownerUserId: 99,
                tenantInstitutionId: 3,
                user: $this->user(id: 5, institutionId: 3, role: 'etudiant'),
                moderatorRoles: ['admin', 'administrateur', 'superAdmin', 'coordinateur'],
            ),
        );
    }

    /**
     * T11 — `superAdmin` (alias intra-tenant admin) is in default moderator roles.
     */
    public function test_allow_superAdmin_intra_tenant_via_default(): void
    {
        self::assertTrue(
            $this->subject()->isForumActionAuthorized(
                ownerUserId: 99,
                tenantInstitutionId: 3,
                user: $this->user(id: 5, institutionId: 3, role: 'superAdmin'),
            ),
        );
    }

    /**
     * T12 — `administrateur` (French alias) is in default moderator roles.
     */
    public function test_allow_administrateur_alias_intra_tenant(): void
    {
        self::assertTrue(
            $this->subject()->isForumActionAuthorized(
                ownerUserId: 99,
                tenantInstitutionId: 3,
                user: $this->user(id: 5, institutionId: 3, role: 'administrateur'),
            ),
        );
    }

    /**
     * T13 — supradmin even with null institution_id on user (platform manager
     *        has no institution scope by design).
     */
    public function test_allow_supradmin_with_null_institution(): void
    {
        self::assertTrue(
            $this->subject()->isForumActionAuthorized(
                ownerUserId: 1,
                tenantInstitutionId: 5,
                user: $this->user(id: 1, institutionId: null, role: 'supradmin'),
            ),
        );
    }

    /**
     * T14 — A non-supradmin user with `null` institution_id cannot pass tenant
     *        check (institution_id mismatch with the resource).
     */
    public function test_deny_user_with_null_institution_against_real_tenant(): void
    {
        self::assertFalse(
            $this->subject()->isForumActionAuthorized(
                ownerUserId: 99,
                tenantInstitutionId: 5,
                user: $this->user(id: 1, institutionId: null, role: 'admin'),
            ),
        );
    }
}
