<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Concerns;

use App\Models\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #524 — verrou unitaire DIRECT des prédicats d'autorisation les plus
 * sensibles de {@see \App\Models\Concerns\InteractsWithRoles}.
 *
 * `isPlatformSupradmin()` est l'UNIQUE garde du privilège PLATEFORME cross-tenant
 * (cf. #497/#511/#512). Sa comparaison STRICTE `role === 'supradmin'` doit :
 *   - être vraie UNIQUEMENT pour le rôle plateforme canonique `'supradmin'` ;
 *   - être FAUSSE pour l'admin d'INSTITUTION `'superAdmin'` (que l'enum normalise
 *     pourtant vers Role::Supradmin — d'où le choix volontaire de NE PAS passer
 *     par asRoleEnum()).
 *
 * Test unitaire pur (aucune DB) : l'attribut `role` suffit.
 *
 * @see app/Models/Concerns/InteractsWithRoles.php
 */
#[CoversClass(User::class)]
final class InteractsWithRolesTest extends TestCase
{
    private function userWithRole(?string $role): User
    {
        $user = new User();
        $user->role = $role;

        return $user;
    }

    /**
     * @return array<string, array{?string, bool}>
     */
    public static function platformSupradminProvider(): array
    {
        return [
            'supradmin plateforme (canonique)' => ['supradmin', true],
            'superAdmin (admin institution)'   => ['superAdmin', false],
            'admin'                            => ['admin', false],
            'coordinateur'                     => ['coordinateur', false],
            'enseignant'                       => ['enseignant', false],
            'etudiant'                         => ['etudiant', false],
            'variante casse Supradmin'         => ['Supradmin', false],
            'rôle inconnu'                     => ['hacker', false],
            'rôle null'                        => [null, false],
        ];
    }

    #[DataProvider('platformSupradminProvider')]
    public function test_is_platform_supradmin_is_strict(?string $role, bool $expected): void
    {
        self::assertSame($expected, $this->userWithRole($role)->isPlatformSupradmin());
    }

    /**
     * @return array<string, array{?string, bool}>
     */
    public static function managerProvider(): array
    {
        return [
            'coordinateur' => ['coordinateur', true],
            'admin'        => ['admin', true],
            'superAdmin'   => ['superAdmin', true],   // admin (enum) → isManager
            'supradmin'    => ['supradmin', true],    // admin au sens large → isManager
            'enseignant'   => ['enseignant', false],
            'etudiant'     => ['etudiant', false],
            'null'         => [null, false],
        ];
    }

    #[DataProvider('managerProvider')]
    public function test_is_manager(?string $role, bool $expected): void
    {
        self::assertSame($expected, $this->userWithRole($role)->isManager());
    }
}
