<?php

declare(strict_types=1);

namespace Tests\Feature\RateLimit;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #947 — toute lecture authentifiée a une limite de débit (ADR-947-01).
 *
 * Un limiteur nommé, `lecture`, posé une fois sur le groupe `api` : il ne
 * compte que les lectures d'un compte authentifié, par FAMILLE et par compte,
 * pour qu'un sondage ne consomme pas le budget de navigation. Le supradmin de
 * plateforme en est exempté, comme des autres limiteurs.
 *
 * Les seuils viennent de la mesure du parcours E2E (ADR-947-01) : le débit
 * maximal d'un compte, borné par le haut, sur une minute, × 2. Le parcours
 * complet du compte le plus chargé est rejoué ici : il ne doit recevoir aucun
 * 429.
 *
 * La limite ne dépend pas du mode de l'école : les deux volets sont figés.
 */
final class LimiteDesLecturesTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const NAVIGATION = 300;

    private const SUIVI = 60;

    private const AGREGATS = 30;

    public function test_toute_lecture_authentifiee_passe_par_la_limite(): void
    {
        $routeur = app('router');
        $inspectees = 0;
        $sans = [];

        foreach (Route::getRoutes() as $route) {
            // `api/__test/*` : les sondes que `Tests\TestCase::setUp()` enregistre
            // hors du groupe `api`. Ce ne sont pas des routes de l'application.
            if (! str_starts_with($route->uri(), 'api/') || str_starts_with($route->uri(), 'api/__test/')
                || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $middlewares = $routeur->gatherRouteMiddleware($route);
            if (preg_grep('/Authenticate:sanctum$/', $middlewares) === []) {
                continue;
            }
            $inspectees++;
            if (preg_grep('/ThrottleRequests(WithRedis)?:lecture$/', $middlewares) === []) {
                $sans[] = $route->uri();
            }
        }

        self::assertGreaterThan(90, $inspectees, 'La garde n\'a presque rien inspecté : elle ne prouverait rien.');
        self::assertSame(
            [],
            $sans,
            sprintf("%d lecture(s) authentifiée(s) sans le limiteur `lecture`, sur %d :\n  - %s", count($sans), $inspectees, implode("\n  - ", $sans))
        );
    }

    /**
     * @return iterable<string, array{string, string, int, InstitutionMode}>
     */
    public static function familles(): iterable
    {
        $chemins = [
            'navigation' => ['/api/lessons/my-courses', 'navigation', self::NAVIGATION],
            'navigation, alias v1' => ['/api/v1/lessons/my-courses', 'navigation', self::NAVIGATION],
            'suivi : non lues' => ['/api/notifications/unread-count', 'suivi', self::SUIVI],
            'suivi : import' => ['/api/lms/imports/12', 'suivi', self::SUIVI],
            'suivi : temps d\'une évaluation' => ['/api/evaluations/7/time-status', 'suivi', self::SUIVI],
            'agrégats : analytique' => ['/api/admin/analytics/system-metrics', 'agregats', self::AGREGATS],
            'agrégats : statistiques enseignant' => ['/api/teacher/stats', 'agregats', self::AGREGATS],
        ];
        foreach ($chemins as $nom => $cas) {
            yield "$nom, école KLASSCI" => [...$cas, InstitutionMode::Klassci];
            yield "$nom, école autonome" => [...$cas, InstitutionMode::Standalone];
        }
    }

    #[DataProvider('familles')]
    public function test_chaque_famille_a_son_seuil_et_son_seau(string $chemin, string $famille, int $seuil, InstitutionMode $mode): void
    {
        $compte = $this->compte($mode, 'enseignant');

        $limite = $this->limite('GET', $chemin, $compte);

        self::assertInstanceOf(Limit::class, $limite);
        self::assertNotInstanceOf(Unlimited::class, $limite);
        self::assertSame($seuil, $limite->maxAttempts);
        self::assertSame(60, $limite->decaySeconds);
        self::assertSame("lecture|$famille|{$compte->id}", $limite->key);
    }

    public function test_une_ecriture_un_invite_et_le_supradmin_ne_sont_pas_comptes(): void
    {
        $compte = $this->compte(InstitutionMode::Standalone, 'enseignant');
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        self::assertInstanceOf(Unlimited::class, $this->limite('POST', '/api/lessons', $compte));
        self::assertInstanceOf(Unlimited::class, $this->limite('GET', '/api/lessons/my-courses', null));
        self::assertInstanceOf(Unlimited::class, $this->limite('GET', '/api/admin/institutions', $supradmin));
    }

    /**
     * Le parcours E2E complet du compte le plus chargé (enseignant, 61 écrans),
     * rejoué dans UNE minute : aucun seau ne déborde. Plus strict que la mesure,
     * qui bornait une fenêtre de 46 écrans.
     */
    #[DataProvider('volets')]
    public function test_le_parcours_e2e_complet_d_un_compte_ne_recoit_aucun_429(InstitutionMode $mode): void
    {
        $parcours = json_decode((string) file_get_contents(__DIR__.'/parcours-e2e-enseignant.json'), true);
        self::assertIsArray($parcours);
        $compte = $this->compte($mode, 'enseignant');
        $coups = [];
        $plafonds = [];

        foreach ($parcours['appels'] as $chemin) {
            $limite = $this->limite('GET', $chemin, $compte);
            self::assertInstanceOf(Limit::class, $limite);
            if ($limite instanceof Unlimited) {
                continue;
            }
            $coups[$limite->key] = ($coups[$limite->key] ?? 0) + 1;
            $plafonds[$limite->key] = $limite->maxAttempts;
        }

        self::assertGreaterThan(100, array_sum($coups), 'Le rejeu n\'a presque rien compté : il ne prouverait rien.');
        foreach ($coups as $cle => $n) {
            self::assertLessThanOrEqual($plafonds[$cle], $n, "Le seau $cle recevrait un 429 pendant le parcours E2E ($n > {$plafonds[$cle]}).");
        }
    }

    /**
     * Au-delà du seuil, la lecture reçoit un 429 avec `Retry-After`, et les
     * autres familles du même compte restent ouvertes.
     */
    #[DataProvider('volets')]
    public function test_au_dela_du_seuil_la_lecture_recoit_un_429(InstitutionMode $mode): void
    {
        $compte = $this->compte($mode, 'enseignant');

        for ($i = 0; $i < self::SUIVI; $i++) {
            $this->asTenant($compte)->getJson('/api/notifications/unread-count')->assertOk();
        }

        $this->asTenant($compte)->getJson('/api/notifications/unread-count')
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->asTenant($compte)->getJson('/api/notifications/recent')->assertOk();
    }

    /**
     * @return array<string, array{InstitutionMode}>
     */
    public static function volets(): array
    {
        return [
            'école KLASSCI' => [InstitutionMode::Klassci],
            'école autonome' => [InstitutionMode::Standalone],
        ];
    }

    private function limite(string $methode, string $chemin, ?User $compte): mixed
    {
        $limiteur = RateLimiter::limiter('lecture');
        self::assertNotNull($limiteur, 'Aucun limiteur nommé `lecture` n\'est déclaré.');

        $requete = Request::create($chemin, $methode);
        $requete->setUserResolver(static fn (): ?User => $compte);

        return $limiteur($requete);
    }

    private function compte(InstitutionMode $mode, string $role): User
    {
        $factory = Institution::factory();
        $ecole = ($mode === InstitutionMode::Standalone ? $factory->standalone() : $factory)->create(['mode' => $mode]);

        return User::factory()->create(['institution_id' => $ecole->id, 'role' => $role]);
    }
}
