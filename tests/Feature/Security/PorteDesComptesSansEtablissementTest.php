<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les routes `plateforme` sont au supradmin, pas à tout compte sans établissement (#972).
 *
 * La porte (`etablissement.requis`) laissait passer, sur une route `plateforme`,
 * TOUT compte sans établissement, quel que soit son rôle : un coordinateur ou un
 * admin d'école à `institution_id` nul y atteignait les compteurs et l'envoi de
 * notifications, sans tenant. Désormais, seul le supradmin plateforme y passe.
 *
 * Un compte, quel qu'il soit, garde deux routes marquées `tout.compte` : se
 * déconnecter (révoquer son propre jeton ne lit et n'expose rien) et lire son
 * propre compte (l'écran d'erreur en a besoin).
 */
final class PorteDesComptesSansEtablissementTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function routesPlateformeRefusees(): iterable
    {
        yield 'compteurs de notifications' => ['coordinateur', 'GET', '/api/admin/notifications/stats'];
        yield 'envoi d\'une notification' => ['coordinateur', 'POST', '/api/admin/notifications/create'];
        yield 'ses notifications' => ['coordinateur', 'GET', '/api/notifications'];
        yield 'configuration' => ['superAdmin', 'GET', '/api/configuration'];
        yield 'compte brut' => ['enseignant', 'GET', '/api/user'];
    }

    #[DataProvider('routesPlateformeRefusees')]
    public function test_un_compte_sans_etablissement_non_supradmin_est_refuse_sur_une_route_plateforme(string $role, string $methode, string $url): void
    {
        $this->disableKlassciMiddleware();
        $jeton = $this->compteSansEtablissement($role)->createToken('porte-972')->plainTextToken;

        $this->withToken($jeton)->json($methode, $url)
            ->assertForbidden()
            ->assertJsonPath('reason', 'no_institution');
    }

    public function test_un_compte_sans_etablissement_lit_son_compte_et_se_deconnecte(): void
    {
        $compte = $this->compteSansEtablissement('coordinateur');
        $jeton = $compte->createToken('porte-972')->plainTextToken;

        $this->withToken($jeton)->getJson('/api/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($jeton)->postJson('/api/auth/logout')->assertOk();

        self::assertSame(0, $compte->tokens()->count(), 'Le jeton n\'a pas été révoqué.');
    }

    public function test_le_supradmin_garde_ses_routes_plateforme(): void
    {
        $this->disableKlassciMiddleware();
        $jeton = User::factory()->create(['institution_id' => null, 'role' => 'supradmin'])->createToken('porte-972')->plainTextToken;

        $this->withToken($jeton)->getJson('/api/admin/notifications/stats')->assertOk();
    }

    public function test_un_membre_d_ecole_garde_ses_routes_plateforme(): void
    {
        $this->disableKlassciMiddleware();
        $membre = User::factory()->create([
            'institution_id' => Institution::factory()->create()->id,
            'role' => 'coordinateur',
        ]);

        $this->withToken($membre->createToken('porte-972')->plainTextToken)
            ->getJson('/api/admin/notifications/stats')
            ->assertOk();
    }

    /** Ajouter une route ouverte à tout compte est un choix conscient, relu. */
    public function test_la_liste_des_routes_ouvertes_a_tout_compte_est_figee(): void
    {
        $marquees = [];
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/') && in_array('tout.compte', $route->gatherMiddleware(), true)) {
                $marquees[] = $this->cle($route);
            }
        }
        $marquees = array_values(array_unique($marquees));
        sort($marquees);

        self::assertSame(['GET auth/me', 'POST auth/logout'], $marquees);
    }

    private function compteSansEtablissement(string $role): User
    {
        return User::factory()->sansEtablissement()->createQuietly(['institution_id' => null, 'role' => $role]);
    }

    private function cle(Route $route): string
    {
        $prefixe = str_starts_with($route->uri(), 'api/v1/') ? 'api/v1/' : 'api/';
        $methode = array_values(array_diff($route->methods(), ['HEAD']))[0] ?? 'GET';

        return $methode.' '.substr($route->uri(), strlen($prefixe));
    }
}
