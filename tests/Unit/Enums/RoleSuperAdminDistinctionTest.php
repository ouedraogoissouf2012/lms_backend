<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\Role;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Invariant de sécurité : `superAdmin` et `supradmin` sont DEUX rôles distincts.
 *
 *   - `supradmin`  → gestionnaire de la PLATEFORME, cross-tenant, `institution_id = NULL`
 *   - `superAdmin` → administrateur d'UN établissement, strictement intra-tenant
 *
 * Deux noms qui ne diffèrent que par une lettre et une casse, pour deux portées
 * radicalement différentes. Avant ce test, `Role::tryFromString('superAdmin')`
 * renvoyait `Role::Supradmin` : un admin d'établissement était promu gestionnaire
 * de plateforme par une simple conversion. Le dépôt compensait avec dix commentaires
 * « NE PAS utiliser asRoleEnum() » disséminés dans les FormRequests, les services et
 * le provider de rate limiting.
 *
 * Un garde-fou qu'il faut répéter dix fois n'est pas un garde-fou. Ces tests rendent
 * la confusion impossible plutôt qu'improbable.
 */
final class RoleSuperAdminDistinctionTest extends TestCase
{
    #[Test]
    public function super_admin_ne_se_normalise_jamais_en_supradmin_plateforme(): void
    {
        $resolved = Role::tryFromString('superAdmin');

        $this->assertNotSame(
            Role::Supradmin,
            $resolved,
            "'superAdmin' (admin d'établissement) ne doit JAMAIS devenir Role::Supradmin "
            .'(gestionnaire plateforme) : ce serait une escalade de privilège silencieuse.'
        );
    }

    #[Test]
    public function supradmin_plateforme_se_resout_correctement(): void
    {
        $this->assertSame(Role::Supradmin, Role::tryFromString('supradmin'));
    }

    #[Test]
    public function les_deux_roles_sont_des_cases_distinctes(): void
    {
        $this->assertNotSame(Role::tryFromString('superAdmin'), Role::tryFromString('supradmin'));
    }

    /**
     * La casse ne doit pas décider de la portée : ni `SUPERADMIN` ni `SuperAdmin`
     * ne doivent ouvrir l'accès plateforme.
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('variantesDeCasse')]
    public function aucune_variante_de_casse_ne_donne_le_role_plateforme(string $variante): void
    {
        $this->assertNotSame(
            Role::Supradmin,
            Role::tryFromString($variante),
            "La variante '{$variante}' ne doit pas ouvrir l'accès cross-tenant."
        );
    }

    /** @return array<string, array{string}> */
    public static function variantesDeCasse(): array
    {
        return [
            'camelCase'  => ['superAdmin'],
            'majuscules' => ['SUPERADMIN'],
            'capitalise' => ['SuperAdmin'],
        ];
    }

    #[Test]
    public function le_supradmin_plateforme_est_strictement_plus_permissif(): void
    {
        $etablissement = Role::tryFromString('superAdmin');
        $this->assertNotNull($etablissement);

        $this->assertTrue(
            Role::Supradmin->isMorePermissiveThan($etablissement),
            'Le gestionnaire de plateforme doit dominer l\'admin d\'établissement, '
            .'sinon la détection d\'escalade de EnsureKlassciSync est inversée.'
        );
    }

    #[Test]
    public function seul_le_role_plateforme_passe_la_garde_stricte(): void
    {
        $plateforme = new User(['role' => 'supradmin']);
        $etablissement = new User(['role' => 'superAdmin']);

        $this->assertTrue($plateforme->isPlatformSupradmin());
        $this->assertFalse(
            $etablissement->isPlatformSupradmin(),
            "isPlatformSupradmin() garde l'accès cross-tenant : un admin d'établissement "
            .'ne doit jamais le franchir.'
        );
    }

    /**
     * `asRoleEnum()` était le piège central : les FormRequests qui l'utilisaient
     * pour vérifier `=== Role::Supradmin` laissaient passer les `superAdmin`.
     */
    #[Test]
    public function as_role_enum_ne_promeut_pas_l_admin_d_etablissement(): void
    {
        $etablissement = new User(['role' => 'superAdmin']);

        $this->assertNotSame(
            Role::Supradmin,
            $etablissement->asRoleEnum(),
            'asRoleEnum() ne doit plus promouvoir superAdmin : c\'est ce qui obligeait '
            .'le dépôt à répéter « NE PAS utiliser asRoleEnum() » à dix endroits.'
        );
    }
}
