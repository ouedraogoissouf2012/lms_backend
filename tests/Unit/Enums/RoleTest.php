<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Issue #121 — Tests Unit pure de `App\Enums\Role`.
 *
 * Aucune dépendance DB/HTTP : l'enum est un value object stateless.
 *
 * Spec: `.claude/specs/role-enum/`
 */
#[CoversClass(Role::class)]
final class RoleTest extends TestCase
{
    public function test_cases_have_expected_canonical_values(): void
    {
        self::assertSame('etudiant',     Role::Etudiant->value);
        self::assertSame('enseignant',   Role::Enseignant->value);
        self::assertSame('coordinateur', Role::Coordinateur->value);
        self::assertSame('admin',        Role::Admin->value);
        self::assertSame('supradmin',    Role::Supradmin->value);
    }

    public function test_try_from_string_returns_canonical_for_fr_input(): void
    {
        self::assertSame(Role::Etudiant,     Role::tryFromString('etudiant'));
        self::assertSame(Role::Enseignant,   Role::tryFromString('enseignant'));
        self::assertSame(Role::Coordinateur, Role::tryFromString('coordinateur'));
        self::assertSame(Role::Admin,        Role::tryFromString('admin'));
        self::assertSame(Role::Supradmin,    Role::tryFromString('supradmin'));
    }

    public function test_try_from_string_normalizes_en_aliases(): void
    {
        self::assertSame(Role::Etudiant,     Role::tryFromString('student'));
        self::assertSame(Role::Enseignant,   Role::tryFromString('teacher'));
        self::assertSame(Role::Coordinateur, Role::tryFromString('coordinator'));
    }

    public function test_try_from_string_normalizes_admin_aliases(): void
    {
        self::assertSame(Role::Admin, Role::tryFromString('administrateur'));
    }

    /**
     * #102 — `superAdmin` (admin d'etablissement) et `supradmin` (gestionnaire
     * plateforme) sont deux roles distincts. Ce test protegeait auparavant le
     * comportement inverse : la normalisation promouvait l'un vers l'autre.
     */
    public function test_institution_super_admin_is_not_platform_supradmin(): void
    {
        self::assertSame(Role::SuperAdmin, Role::tryFromString('superAdmin'));
        self::assertSame(Role::Supradmin,  Role::tryFromString('supradmin'));
        self::assertNotSame(Role::Supradmin, Role::tryFromString('superAdmin'));
    }

    public function test_aliases_do_not_include_institution_super_admin(): void
    {
        self::assertSame(['supradmin'], Role::Supradmin->aliases());
        self::assertContains('teacher', Role::Enseignant->aliases());
        self::assertNotContains('superAdmin', Role::Supradmin->aliases());
    }

    /**
     * Issue #132 (REQ-1) — alias historique avec accent aigu. 2 sites pré-existants
     * (`SearchController`, `LMSSeancesController`) supportaient explicitement ce variant
     * avant la migration vers `User::isStudent()`. L'enum doit le normaliser.
     */
    public function test_try_from_string_accepts_accented_etudiant_alias(): void
    {
        self::assertSame(Role::Etudiant, Role::tryFromString('étudiant'));
    }

    public function test_try_from_string_returns_null_for_invalid(): void
    {
        self::assertNull(Role::tryFromString('hacker'));
        self::assertNull(Role::tryFromString(null));
        self::assertNull(Role::tryFromString(''));
        self::assertNull(Role::tryFromString('ETUDIANT'));    // case-sensitive (strict)
        self::assertNull(Role::tryFromString(' etudiant '));  // pas de trim
    }

    public function test_permissivity_returns_expected_levels(): void
    {
        self::assertSame(1, Role::Etudiant->permissivity());
        self::assertSame(2, Role::Enseignant->permissivity());
        self::assertSame(3, Role::Coordinateur->permissivity());
        self::assertSame(4, Role::Admin->permissivity());
        self::assertSame(5, Role::SuperAdmin->permissivity());
        self::assertSame(6, Role::Supradmin->permissivity());
        self::assertTrue(Role::Supradmin->isMorePermissiveThan(Role::SuperAdmin));
    }

    public function test_is_admin_returns_true_for_admin_and_supradmin_only(): void
    {
        self::assertTrue(Role::Admin->isAdmin());
        self::assertTrue(Role::Supradmin->isAdmin());

        self::assertFalse(Role::Etudiant->isAdmin());
        self::assertFalse(Role::Enseignant->isAdmin());
        self::assertFalse(Role::Coordinateur->isAdmin());
    }

    public function test_is_more_permissive_than(): void
    {
        // Strict greater-than
        self::assertTrue(Role::Supradmin->isMorePermissiveThan(Role::Etudiant));
        self::assertTrue(Role::Admin->isMorePermissiveThan(Role::Coordinateur));
        self::assertTrue(Role::Coordinateur->isMorePermissiveThan(Role::Enseignant));

        // False direction
        self::assertFalse(Role::Etudiant->isMorePermissiveThan(Role::Supradmin));
        self::assertFalse(Role::Enseignant->isMorePermissiveThan(Role::Admin));
    }

    public function test_is_more_permissive_than_same_role_returns_false(): void
    {
        self::assertFalse(Role::Etudiant->isMorePermissiveThan(Role::Etudiant));
        self::assertFalse(Role::Admin->isMorePermissiveThan(Role::Admin));
        self::assertFalse(Role::Supradmin->isMorePermissiveThan(Role::Supradmin));
    }
}
