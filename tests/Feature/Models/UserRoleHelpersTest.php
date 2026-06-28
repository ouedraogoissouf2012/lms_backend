<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #121 — Test régression Feature : vérifie que les méthodes
 * `User::isAdmin/isTeacher/isStudent/isCoordinator()` retournent les bons
 * booléens pour tous les alias EN/FR historiques de la DB, après le refactor
 * vers `App\Enums\Role::tryFromString`.
 *
 * Preuve d'équivalence runtime : avant/après #121, ces 4 méthodes
 * retournent les mêmes valeurs pour les mêmes inputs.
 *
 * Spec: `.claude/specs/role-enum/`
 *
 * @see \App\Models\User::asRoleEnum
 * @see \App\Models\User::isAdmin
 * @see \App\Models\User::isTeacher
 * @see \App\Models\User::isStudent
 * @see \App\Models\User::isCoordinator
 */
final class UserRoleHelpersTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {

        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role'           => $role,
        ]);
    }

    public function test_user_helpers_handle_all_canonical_and_alias_roles(): void
    {
        // [role_db_value, isStudent, isTeacher, isCoordinator, isAdmin, isStaff, isManager]
        $cases = [
            // Canoniques FR
            ['etudiant',       true,  false, false, false, false, false],
            ['enseignant',     false, true,  false, false, true,  false],
            ['coordinateur',   false, false, true,  false, true,  true],
            ['admin',          false, false, false, true,  true,  true],
            ['supradmin',      false, false, false, true,  true,  true],

            // Alias EN historiques
            ['student',        true,  false, false, false, false, false],
            ['teacher',        false, true,  false, false, true,  false],
            ['coordinator',    false, false, true,  false, true,  true],

            // Alias admin historiques
            ['administrateur', false, false, false, true,  true,  true],
            ['superAdmin',     false, false, false, true,  true,  true],
        ];

        foreach ($cases as [$role, $student, $teacher, $coord, $admin, $staff, $manager]) {
            $user = $this->userWithRole($role);

            self::assertSame(
                $student,
                $user->isStudent(),
                "isStudent() pour role={$role} doit retourner " . ($student ? 'true' : 'false'),
            );
            self::assertSame(
                $teacher,
                $user->isTeacher(),
                "isTeacher() pour role={$role} doit retourner " . ($teacher ? 'true' : 'false'),
            );
            self::assertSame(
                $coord,
                $user->isCoordinator(),
                "isCoordinator() pour role={$role} doit retourner " . ($coord ? 'true' : 'false'),
            );
            self::assertSame(
                $admin,
                $user->isAdmin(),
                "isAdmin() pour role={$role} doit retourner " . ($admin ? 'true' : 'false'),
            );
            self::assertSame(
                $staff,
                $user->isStaff(),
                "isStaff() pour role={$role} doit retourner " . ($staff ? 'true' : 'false'),
            );
            self::assertSame(
                $manager,
                $user->isManager(),
                "isManager() pour role={$role} doit retourner " . ($manager ? 'true' : 'false'),
            );
        }
    }

    public function test_user_helpers_return_false_for_unknown_role(): void
    {
        $user = $this->userWithRole('hacker');

        self::assertFalse($user->isStudent());
        self::assertFalse($user->isTeacher());
        self::assertFalse($user->isCoordinator());
        self::assertFalse($user->isAdmin());
        self::assertFalse($user->isStaff());
        self::assertFalse($user->isManager());
    }

    public function test_as_role_enum_returns_null_for_unknown_role(): void
    {
        $user = $this->userWithRole('hacker');

        self::assertNull($user->asRoleEnum());
    }

    /**
     * Defensive coverage post-audit spec-architect LOW : couvre le cas fail-soft
     * pour les valeurs invalides côté DB.
     *
     * Note : le cas `role = NULL` est volontairement non testé ici parce que la
     * migration `2025_10_14_000001_add_klassci_fields_to_users_table.php:19` définit
     * `$table->string('role')->default('student')` sans `->nullable()`. La colonne
     * NOT NULL rend `null` structurellement impossible — couvert au niveau Unit
     * par `RoleTest::test_try_from_string_returns_null_for_invalid`.
     */
    public function test_user_helpers_return_false_for_empty_role(): void
    {
        $user = $this->userWithRole('');

        self::assertNull($user->asRoleEnum());
        self::assertFalse($user->isStudent());
        self::assertFalse($user->isTeacher());
        self::assertFalse($user->isCoordinator());
        self::assertFalse($user->isAdmin());
        self::assertFalse($user->isStaff());
        self::assertFalse($user->isManager());
    }
}
