<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\EnsureSchoolMemberOrPlatformRoute;
use App\Http\Middleware\MarksPlatformRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * Les routes qu'un compte sans établissement atteint, et elles seules.
 *
 * Quatre propriétés, qui échouent dès qu'une route s'écarte de la règle :
 * - les routes servies HORS de la porte, sur TOUTE l'application, sont figées
 *   ci-dessous : une route ajoutée ailleurs que dans `routes/api.php` échoue ;
 * - toute route API passe par la porte (`etablissement.requis`) ;
 * - la liste des routes marquées `plateforme` est FIGÉE ci-dessous, en clair,
 *   pour être relue par un humain : en ajouter une est un choix conscient ;
 * - toute route authentifiée NON marquée refuse le supradmin (403, le message de
 *   la porte), quelle que soit sa méthode, avant tout contrôleur.
 *
 * @see app/Http/Middleware/EnsureSchoolMemberOrPlatformRoute.php
 */
final class PlatformRoutesListTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les routes de GESTION plateforme (méthode, chemin sans le préfixe `api/`).
     * Relue : écoles, demandes d'école, configuration, intégrations,
     * notifications d'administration, la session et ses propres notifications.
     * La gestion des COMPTES d'école (`users`, `recent-users`) n'y est pas :
     * décision A8 en attente, valeur prudente.
     */
    private const ROUTES_PLATEFORME = [
        'GET institutions/active',
        'GET institution/current',
        'GET auth/me',
        'POST auth/logout',
        'POST auth/refresh',
        'GET auth/check',
        'GET user',
        'GET proxy/test-connection',
        'GET configuration',
        'PUT configuration',
        'DELETE configuration',
        'POST integrations/connect',
        'POST integrations/authorize',
        'POST integrations/test',
        'POST integrations/disconnect',
        'GET notifications',
        'GET notifications/unread-count',
        'GET notifications/recent',
        'POST notifications/{id}/mark-as-read',
        'POST notifications/mark-all-as-read',
        'DELETE notifications/{id}',
        'DELETE notifications/read/all',
        'POST admin/notifications/create',
        'GET admin/notifications/stats',
        'GET admin/institutions',
        'GET admin/institutions/{id}',
        'POST admin/institutions',
        'PUT admin/institutions/{id}',
        'PATCH admin/institutions/{id}/toggle',
        'PATCH admin/institutions/{id}/mode',
        'POST admin/institutions/{id}/test-connection',
        'DELETE admin/institutions/{id}',
        'GET admin/school-requests',
        'POST admin/school-requests/{schoolRequest}/validate',
        'POST admin/school-requests/{schoolRequest}/refuse',
    ];

    /**
     * Les routes de l'application servies HORS de la porte (méthode, chemin).
     * Aucune n'est authentifiée par Sanctum : la page d'accueil, la sonde de
     * vie, les fichiers du disque local (URL signées), le cookie CSRF, et la
     * documentation. Toute autre route, d'où qu'elle soit enregistrée, doit
     * passer par la porte.
     */
    private const ROUTES_HORS_PORTE = [
        'GET /',
        'GET up',
        'GET storage/{path}',
        'PUT storage/{path}',
        'GET sanctum/csrf-cookie',
        'GET docs',
        'GET docs/asset/{asset}',
    ];

    /** TOUTES les routes, et non les seules `api/` : une route ajoutée ailleurs échoue ici. */
    public function test_les_routes_hors_porte_sont_figees(): void
    {
        $horsPorte = [];
        foreach ($this->routesApplication() as $route) {
            if (! in_array('etablissement.requis', $route->gatherMiddleware(), true)) {
                $horsPorte[] = $this->cle($route, '');
            }
        }
        sort($horsPorte);
        $attendues = self::ROUTES_HORS_PORTE;
        sort($attendues);

        self::assertSame($attendues, $horsPorte, 'une route echappe a la porte : la passer dans routes/api.php, ou la relire puis mettre a jour ROUTES_HORS_PORTE');
    }

    public function test_toute_route_api_passe_par_la_porte(): void
    {
        $horsPorte = array_values(array_map(
            fn (Route $route): string => $this->cle($route, 'api/'),
            array_filter($this->routesApi(), static fn (Route $route): bool => ! in_array('etablissement.requis', $route->gatherMiddleware(), true)),
        ));

        self::assertSame([], $horsPorte, 'ces routes API ne passent pas par la porte des comptes sans etablissement');
    }

    public function test_la_liste_des_routes_plateforme_est_figee(): void
    {
        $marquees = [];
        foreach ($this->routesApi() as $route) {
            if (in_array(MarksPlatformRoute::ALIAS, $route->gatherMiddleware(), true)) {
                $marquees[] = $this->cle($route, str_starts_with($route->uri(), 'api/v1/') ? 'api/v1/' : 'api/');
            }
        }
        $marquees = array_values(array_unique($marquees));
        sort($marquees);
        $attendues = self::ROUTES_PLATEFORME;
        sort($attendues);

        self::assertSame($attendues, $marquees, 'la liste des routes plateforme a change : la relire, puis mettre a jour ROUTES_PLATEFORME');
    }

    /**
     * Le refus par défaut, MESURÉ : chaque route authentifiée non marquée, appelée
     * par le supradmin avec un vrai jeton, rend le 403 de la porte. Une route
     * ajoutée demain y est soumise sans rien écrire ici.
     */
    public function test_toute_route_authentifiee_non_marquee_refuse_le_supradmin(): void
    {
        $this->disableKlassciMiddleware();
        $jeton = User::factory()->create(['institution_id' => null, 'role' => 'supradmin'])->createToken('test')->plainTextToken;
        $ouvertes = [];

        foreach ($this->routesApi() as $route) {
            $intergiciels = $route->gatherMiddleware();
            if (! str_starts_with($route->uri(), 'api/') || str_starts_with($route->uri(), 'api/v1/')
                || ! in_array('auth:sanctum', $intergiciels, true) || in_array(MarksPlatformRoute::ALIAS, $intergiciels, true)) {
                continue;
            }

            $this->app['auth']->forgetGuards();
            $reponse = $this->withToken($jeton)->json($route->methods()[0], '/' . $this->uriConcrete($route));

            if ($reponse->status() !== 403 || $reponse->json('message') !== EnsureSchoolMemberOrPlatformRoute::MESSAGE) {
                $ouvertes[] = $this->cle($route, 'api/') . ' -> ' . $reponse->status();
            }
        }

        self::assertSame([], $ouvertes, 'ces routes non marquees ne refusent pas le supradmin a la porte');
    }

    /**
     * Les routes de l'APPLICATION. La sonde de tenant du harnais
     * (`__test.tenant-scope-probe`, posée par tests/TestCase.php pour les tests
     * d'isolation) n'est servie qu'en test : elle est écartée par son nom, et
     * elle seule.
     *
     * @return list<Route>
     */
    private function routesApplication(): array
    {
        return array_values(array_filter(
            $this->app['router']->getRoutes()->getRoutes(),
            static fn (Route $route): bool => $route->getName() !== '__test.tenant-scope-probe',
        ));
    }

    /** @return list<Route> */
    private function routesApi(): array
    {
        return array_values(array_filter(
            $this->routesApplication(),
            static fn (Route $route): bool => str_starts_with($route->uri(), 'api/'),
        ));
    }

    private function cle(Route $route, string $prefixe): string
    {
        $chemin = substr($route->uri(), strlen($prefixe));

        return $route->methods()[0] . ' ' . ($chemin === false || $chemin === '' ? '' : $chemin);
    }

    /** Les paramètres de chemin reçoivent `1` : la porte décide avant toute résolution de modèle. */
    private function uriConcrete(Route $route): string
    {
        return (string) preg_replace(['~/\{[^}]+\?\}~', '~\{[^}]+\}~'], ['', '1'], $route->uri());
    }
}
