<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Middleware\EnsurePermission;
use App\Models\User;
use App\Services\Autorisation\PermissionsDuRole;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * #1665 lot 1 — le catalogue des permissions et les routes se répondent : une route ne cite qu'une permission du
 * catalogue, une permission du catalogue garde au moins une route, et une valeur inconnue FERME la route.
 */
final class PermissionsDesRoutesTest extends TestCase
{
    public function test_une_route_ne_cite_que_des_permissions_du_catalogue(): void
    {
        $citees = $this->permissionsCitees();

        self::assertNotEmpty($citees, 'Aucune garde permission: trouvée : chemin faux ?');
        self::assertSame([], array_values(array_filter($citees, static fn (string $p): bool => Permission::tryFrom($p) === null)));
    }

    public function test_chaque_permission_du_catalogue_garde_une_route(): void
    {
        $citees = $this->permissionsCitees();
        $mortes = array_filter(Permission::cases(), static fn (Permission $p): bool => ! in_array($p->value, $citees, true));

        self::assertSame([], array_map(static fn (Permission $p): string => $p->value, array_values($mortes)));
    }

    public function test_les_permissions_de_la_plateforme_ne_vont_qu_au_supradmin(): void
    {
        $table = new PermissionsDuRole;
        foreach (Role::cases() as $role) {
            $plateforme = array_filter($table->de($role), static fn (Permission $p): bool => $p->estDeLaPlateforme());
            self::assertSame($role === Role::Supradmin, $plateforme !== [], $role->value);
        }
        self::assertSame([], $table->de(null), 'Un rôle inconnu n\'a aucune permission.');
    }

    public function test_une_permission_inconnue_ferme_la_route_et_se_journalise(): void
    {
        Log::spy();
        $compte = new User;
        $compte->forceFill(['id' => 1, 'role' => 'supradmin']);
        $requete = Request::create('/api/x');
        $requete->setUserResolver(static fn (): User => $compte);

        $reponse = $this->app->make(EnsurePermission::class)->handle($requete, static fn () => response('passe'), 'faute.de.frappe');

        self::assertSame(403, $reponse->getStatusCode());
        Log::shouldHaveReceived('error')->once();
    }

    /**
     * @return list<string>
     */
    private function permissionsCitees(): array
    {
        $citees = [];
        foreach ($this->app->make(Router::class)->getRoutes()->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $nom) {
                if (is_string($nom) && str_starts_with($nom, EnsurePermission::ALIAS.':')) {
                    $citees[] = substr($nom, strlen(EnsurePermission::ALIAS) + 1);
                }
            }
        }

        return array_values(array_unique($citees));
    }
}
