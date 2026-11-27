<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\Role;
use App\Http\Middleware\EnsureGestion;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePersonnel;
use App\Http\Middleware\EnsurePlatformSupradmin;
use App\Http\Middleware\EnsureRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * #1665 lot 1 — la PREUVE du passage aux permissions : la matrice route × rôle, FIGÉE avant le changement
 * (`MatriceRouteRole.json`), est rejouée après, et doit rester identique.
 *
 * La matrice n'est pas lue dans les noms des gardes : elle EXÉCUTE les vraies gardes d'autorisation de chaque
 * route (classe et paramètres résolus par le routeur), pour un compte de chaque valeur de rôle connue, alias
 * compris, et pour un rôle inconnu. Les règles fines (le superAdmin passe tout sauf les routes plateforme, le
 * supradmin plateforme passe tout) sont donc jugées par le code, pas par ce test.
 *
 * `GARDES` liste les middlewares qui autorisent selon le rôle. Une nouvelle garde de ce genre s'y AJOUTE ; le
 * fichier figé ne se régénère jamais pour faire passer un changement d'autorisation : s'il bouge, c'est que qui
 * peut faire quoi a bougé, et cela se déclare.
 *
 * Régénération (création seulement, déclarée en PR) : `FIGER_MATRICE_ROUTE_ROLE=1`.
 */
final class MatriceRouteRoleTest extends TestCase
{
    private const FICHIER = __DIR__.'/MatriceRouteRole.json';

    /** Les gardes d'autorisation par rôle, exécutées pour chaque route. */
    private const GARDES = [
        EnsureRole::class,
        EnsureGestion::class,
        EnsurePersonnel::class,
        EnsurePlatformSupradmin::class,
        // #1665 lot 1 : la garde qui remplace les trois premieres, AJOUTEE (le fichier fige ne bouge pas).
        EnsurePermission::class,
    ];

    private const TOUS = '*';

    public function test_la_matrice_route_role_est_celle_figee(): void
    {
        Log::spy();
        $matrice = $this->matrice();

        if (getenv('FIGER_MATRICE_ROUTE_ROLE') === '1') {
            file_put_contents(self::FICHIER, json_encode($matrice, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            self::markTestSkipped('Matrice figée dans '.basename(self::FICHIER).' : à relire et à déclarer.');
        }

        $figee = json_decode((string) file_get_contents(self::FICHIER), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($figee, $matrice, 'Qui peut faire quoi a changé : la matrice route × rôle diffère de celle figée.');
    }

    public function test_la_matrice_voit_des_refus_et_des_acces(): void
    {
        // Témoin : une matrice où tout passe (gardes non trouvées) ou rien ne passe ne prouverait rien.
        Log::spy();
        $valeurs = array_values($this->matrice());

        self::assertContains(self::TOUS, $valeurs);
        self::assertNotEmpty(array_filter($valeurs, static fn (mixed $v): bool => is_array($v)));
        self::assertContains(['supradmin'], $valeurs, 'Les routes plateforme ne sont ouvertes qu\'au supradmin.');
    }

    /**
     * @return array<string, list<string>|string> « MÉTHODES uri » => les rôles admis, ou `*` si tous le sont
     */
    private function matrice(): array
    {
        $router = $this->app->make(Router::class);
        $sujets = $this->sujets();
        $matrice = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            $gardes = $this->gardes($route, $router);
            $admis = [];
            foreach ($sujets as $nom => $compte) {
                if ($this->passe($gardes, $compte, $route)) {
                    $admis[] = $nom;
                }
            }
            $cle = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
            $matrice[$cle] = count($admis) === count($sujets) ? self::TOUS : $admis;
        }
        ksort($matrice);

        return $matrice;
    }

    /**
     * Un compte par valeur de rôle connue (alias compris), et un rôle inconnu.
     *
     * @return array<string, User>
     */
    private function sujets(): array
    {
        $valeurs = [];
        foreach (Role::cases() as $role) {
            array_push($valeurs, ...$role->aliases());
        }
        $valeurs[] = 'parent';

        $sujets = [];
        foreach (array_values(array_unique($valeurs)) as $i => $valeur) {
            $compte = new User;
            $compte->forceFill(['id' => 900000 + $i, 'role' => $valeur, 'institution_id' => $valeur === 'supradmin' ? null : 1]);
            $sujets[$valeur] = $compte;
        }

        return $sujets;
    }

    /**
     * Les gardes d'autorisation de la route, résolues en classe et paramètres, dans l'ordre.
     *
     * @return list<array{0: class-string, 1: list<string>}>
     */
    private function gardes(Route $route, Router $router): array
    {
        $gardes = [];
        foreach ($route->gatherMiddleware() as $nom) {
            foreach ((array) MiddlewareNameResolver::resolve($nom, $router->getMiddleware(), $router->getMiddlewareGroups()) as $resolu) {
                if (! is_string($resolu)) {
                    continue;
                }
                [$classe, $parametres] = array_pad(explode(':', $resolu, 2), 2, '');
                if (in_array($classe, self::GARDES, true)) {
                    $gardes[] = [$classe, $parametres === '' ? [] : explode(',', $parametres)];
                }
            }
        }

        return $gardes;
    }

    /**
     * @param  list<array{0: class-string, 1: list<string>}>  $gardes
     */
    private function passe(array $gardes, User $compte, Route $route): bool
    {
        $requete = Request::create('/'.$route->uri(), 'GET');
        $requete->setUserResolver(static fn (): User => $compte);
        $suivant = static fn (): Response => new Response('passe', 200);

        foreach ($gardes as [$classe, $parametres]) {
            $garde = $this->app->make($classe);
            $reponse = $garde->handle($requete, $suivant, ...$parametres);
            if ($reponse->getStatusCode() !== 200) {
                return false;
            }
        }

        return true;
    }
}
