<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * #1130 — la sécurité que la spec déclare suit l'authentification réelle.
 *
 * `docs/openapi.yaml` pose une sécurité globale (`sanctum`). Une opération
 * publique doit l'annuler par `security: []`. Sans quoi un client généré exige
 * un jeton : sur `POST /auth/login`, la route qui délivre le jeton.
 *
 * La garde compare, dans les DEUX sens et par opération (méthode et chemin,
 * comme `OpenApiSyncTest` depuis #912), chaque opération documentée à la route
 * réelle :
 * - route sans `auth:sanctum` ⇒ l'opération déclare `security: []` ;
 * - route avec `auth:sanctum` ⇒ l'opération exige une sécurité.
 *
 * L'authentification se lit par `gatherRouteMiddleware`, groupes résolus.
 * Les opérations non documentées restent dans la dette nommée (#809, #912).
 * Aucune école n'intervient : les deux volets sont sans objet.
 */
final class SecuriteDeclareeTest extends TestCase
{
    private const METHODES = ['get', 'put', 'post', 'delete', 'patch'];

    public function test_la_securite_declaree_suit_l_authentification_reelle(): void
    {
        $spec = Yaml::parseFile(base_path('docs/openapi.yaml'));
        self::assertIsArray($spec);
        $globale = $spec['security'] ?? [];
        $reelles = $this->authentificationReelle();
        $comparees = 0;
        $ecarts = [];

        foreach ($spec['paths'] as $chemin => $item) {
            foreach (self::METHODES as $methode) {
                $operation = strtoupper($methode).' '.$chemin;
                if (! isset($item[$methode]) || ! array_key_exists($operation, $reelles)) {
                    continue;
                }
                $comparees++;
                $declaree = (array_key_exists('security', $item[$methode]) ? $item[$methode]['security'] : $globale) !== [];

                if ($declaree && ! $reelles[$operation]) {
                    $ecarts[] = "$operation : route publique, la spec exige une sécurité (`security: []` manquant)";
                } elseif (! $declaree && $reelles[$operation]) {
                    $ecarts[] = "$operation : route authentifiée, la spec la déclare publique";
                }
            }
        }

        self::assertGreaterThan(90, $comparees, 'La garde n\'a presque rien comparé : elle ne prouverait rien.');
        self::assertSame(
            [],
            $ecarts,
            sprintf("%d opération(s) dont la sécurité déclarée ment, sur %d :\n  - %s", count($ecarts), $comparees, implode("\n  - ", $ecarts))
        );
    }

    /**
     * `METHODE /chemin` → la route exige-t-elle `auth:sanctum` ?
     *
     * @return array<string, bool>
     */
    private function authentificationReelle(): array
    {
        $routeur = app('router');
        $operations = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            // `api/__test/*` : les sondes de `Tests\TestCase::setUp()`, hors de l'application.
            if (! str_starts_with($uri, 'api/') || str_starts_with($uri, 'api/__test/')) {
                continue;
            }
            $chemin = (string) preg_replace('#^api/(v1/|v2/)?#', '/', $uri);
            $authentifiee = preg_grep('/Authenticate:sanctum$/', $routeur->gatherRouteMiddleware($route)) !== [];

            foreach ($route->methods() as $methode) {
                if ($methode !== 'HEAD') {
                    $operations[$methode.' '.$chemin] = ($operations[$methode.' '.$chemin] ?? false) || $authentifiee;
                }
            }
        }

        return $operations;
    }
}
