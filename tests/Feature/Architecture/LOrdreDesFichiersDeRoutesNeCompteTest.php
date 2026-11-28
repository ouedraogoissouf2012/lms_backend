<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Throwable;

/**
 * #1868 : routes/api.php charge CHAQUE fichier de routes/api/ dans une boucle triée ; aucune liste partagée.
 *
 * Ce n'est sûr que si l'ordre des fichiers ne compte pas : Laravel retient la PREMIÈRE route enregistrée qui
 * correspond, et une route d'un fichier pourrait capturer celle d'un autre (`classes/{x}` avant `classes/local`).
 * La garde charge les fichiers dans l'ordre trié, puis dans l'ordre INVERSÉ, et résout chaque route sur sa propre
 * URI (un témoin alphabétique et un témoin numérique par paramètre) : la route trouvée doit être la même. Un
 * chevauchement entre fichiers rougit ici ; on le règle en rangeant les deux routes dans le même fichier, dans
 * l'ordre voulu (la règle des routes d'un même fichier, déjà en usage : `classes.php`, `local` avant `{classeId}`).
 */
final class LOrdreDesFichiersDeRoutesNeCompteTest extends TestCase
{
    public function test_routes_api_charge_ses_fichiers_par_une_boucle_sans_liste(): void
    {
        $source = (string) file_get_contents(base_path('routes/api.php'));

        self::assertDoesNotMatchRegularExpression("#require\\s+__DIR__\\s*\\.\\s*'/api/#", $source, 'Aucune liste de fichiers : la boucle les charge tous.');
        self::assertStringContainsString("glob(__DIR__.'/api/*.php')", $source);
    }

    public function test_la_resolution_est_la_meme_dans_l_ordre_trie_et_dans_l_ordre_inverse(): void
    {
        $fichiers = glob(base_path('routes/api/*.php')) ?: [];
        sort($fichiers);
        self::assertGreaterThan(1, count($fichiers));

        $trie = $this->routeur($fichiers);
        $inverse = $this->routeur(array_reverse($fichiers));

        $ecarts = [];
        $resolutions = 0;
        foreach ($trie->getRoutes()->getRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $methode) {
                foreach (['abc12', '12345'] as $temoin) {
                    $uri = '/'.(string) preg_replace('/\{[a-zA-Z_]+\??\}/', $temoin, $route->uri());
                    $resolutions++;
                    $a = $this->resoudre($trie, $uri, $methode);
                    $b = $this->resoudre($inverse, $uri, $methode);
                    if ($a !== $b) {
                        $ecarts[] = "{$methode} {$uri} : trié → {$a}, inversé → {$b}";
                    }
                }
            }
        }

        self::assertGreaterThan(400, $resolutions, 'La garde doit résoudre toute l\'API.');
        self::assertSame([], $ecarts, 'Une route d\'un fichier en capture une d\'un autre selon l\'ordre de chargement.');
    }

    /** @param  list<string>  $fichiers */
    private function routeur(array $fichiers): Router
    {
        $routeur = new Router($this->app->make('events'), $this->app);
        $facade = Route::getFacadeRoot();
        Route::swap($routeur);
        try {
            $routeur->group(['prefix' => 'api'], function () use ($fichiers): void {
                foreach ($fichiers as $fichier) {
                    require $fichier;
                }
            });
        } finally {
            Route::swap($facade);
        }

        return $routeur;
    }

    private function resoudre(Router $routeur, string $uri, string $methode): string
    {
        try {
            $route = $routeur->getRoutes()->match(Request::create($uri, $methode));
        } catch (Throwable) {
            return 'aucune';
        }

        return $route instanceof RouteDefinition ? implode('|', $route->methods()).' '.$route->uri() : 'aucune';
    }
}
