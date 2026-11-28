<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\Permission;
use App\Http\Middleware\EnsurePermission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * La garde `permission:x` (#1665 lot 1). Migration DÉCLARÉE de deux tests de l'ancienne garde `role:`, retirée :
 *
 * - EnsureRole403ResponseShapeTest (OWASP A06) : le 403 ne dit ni ce qui est exigé, ni le rôle du compte ; le 401
 *   reste minimal. Ici, plus strict : le corps est EXACTEMENT `{success: false, message}`.
 * - EnsureRoleSupradminStrictTest (#511) : seul `supradmin`, à la casse exacte, a la plateforme ; le superAdmin
 *   d'une école a tout le reste ; une variante de casse n'est aucun rôle.
 */
final class EnsurePermissionTest extends TestCase
{
    public function test_le_403_ne_dit_ni_la_permission_ni_le_role(): void
    {
        $reponse = $this->garde($this->compte('etudiant'), Permission::ComptesGerer);

        self::assertSame(403, $reponse->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'Accès refusé - Permissions insuffisantes'], json_decode((string) $reponse->getContent(), true));
    }

    public function test_sans_compte_un_401_minimal(): void
    {
        $requete = Request::create('/api/x');
        $requete->setUserResolver(static fn () => null);

        $reponse = $this->app->make(EnsurePermission::class)->handle($requete, static fn () => response('passe'), Permission::ComptesGerer->value);

        self::assertSame(401, $reponse->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'Non authentifié'], json_decode((string) $reponse->getContent(), true));
    }

    public function test_un_compte_qui_a_la_permission_passe(): void
    {
        $reponse = $this->garde($this->compte('enseignant'), Permission::VisioAnimer);

        self::assertSame(200, $reponse->getStatusCode());
        self::assertSame('passe', $reponse->getContent());
    }

    /**
     * @return array<string, array{0: string, 1: Permission, 2: int}>
     */
    public static function plateforme(): array
    {
        return [
            'supradmin : la plateforme' => ['supradmin', Permission::PlateformeEtablissements, 200],
            'supradmin : tout le reste' => ['supradmin', Permission::VisioEnregistrements, 200],
            'superAdmin : jamais la plateforme' => ['superAdmin', Permission::PlateformeEtablissements, 403],
            'superAdmin : tout le reste' => ['superAdmin', Permission::VisioEnregistrements, 200],
            'admin : jamais la plateforme' => ['admin', Permission::PlateformeDemandesEcole, 403],
            'Supradmin (casse) : rien' => ['Supradmin', Permission::PlateformeEtablissements, 403],
            'SUPRADMIN : rien' => ['SUPRADMIN', Permission::ComptesGerer, 403],
            'SuprAdmin : rien' => ['SuprAdmin', Permission::PlateformeEtablissements, 403],
        ];
    }

    #[DataProvider('plateforme')]
    public function test_seul_le_supradmin_exact_a_la_plateforme(string $role, Permission $permission, int $statut): void
    {
        Log::spy();

        self::assertSame($statut, $this->garde($this->compte($role), $permission)->getStatusCode());
    }

    private function compte(string $role): User
    {
        $compte = new User;
        $compte->forceFill(['id' => 1665, 'role' => $role, 'institution_id' => $role === 'supradmin' ? null : 1]);

        return $compte;
    }

    private function garde(User $compte, Permission $permission): Response
    {
        $requete = Request::create('/api/x');
        $requete->setUserResolver(static fn (): User => $compte);

        return $this->app->make(EnsurePermission::class)->handle($requete, static fn () => response('passe'), $permission->value);
    }
}
