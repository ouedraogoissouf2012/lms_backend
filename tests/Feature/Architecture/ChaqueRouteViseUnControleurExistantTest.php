<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * #1231 — chaque route enregistrée vise une classe de contrôleur qui existe, et une méthode
 * de cette classe.
 *
 * Un nom de contrôleur mal résolu ne lève rien au chargement des routes : `X::class` rend
 * seulement une chaîne. La route répond 500 à son premier appel. C'est ce qu'a failli faire
 * un reformatage Pint de `routes/api/content.php` : il a raccourci un nom complet grâce à
 * un `use` déclaré PLUS BAS dans le fichier, alors qu'un `use` ne vaut que pour le code qui
 * le suit.
 */
final class ChaqueRouteViseUnControleurExistantTest extends TestCase
{
    public function test_chaque_route_vise_une_methode_d_un_controleur_existant(): void
    {
        $inspectees = 0;
        $ecarts = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $action = $route->getAction('uses');
            if (! is_string($action)) {
                continue;
            }

            $inspectees++;
            [$classe, $methode] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];
            if (! class_exists($classe)) {
                $ecarts[] = "{$route->uri()} : classe {$classe} introuvable";
            } elseif (! method_exists($classe, $methode)) {
                $ecarts[] = "{$route->uri()} : méthode {$classe}::{$methode} introuvable";
            }
        }

        self::assertGreaterThan(100, $inspectees, "seulement {$inspectees} route(s) à contrôleur : la garde ne regarde rien");
        self::assertSame([], $ecarts, "Des routes visent un contrôleur ou une méthode qui n'existe pas :\n".implode("\n", $ecarts));
    }
}
