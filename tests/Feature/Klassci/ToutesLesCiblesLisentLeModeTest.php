<?php

declare(strict_types=1);

namespace Tests\Feature\Klassci;

use App\Enums\InstitutionMode;
use App\Exceptions\KlassciUnavailableException;
use App\Jobs\SyncKlassciClasse;
use App\Jobs\SyncUserClasses;
use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\KlassciConfigResolver;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #1249 — toute cible KLASSCI lit le mode de l'école, par la MÊME définition que #1248
 * (`KlassciConfigResolver::cibleDeLEcole`) : priorités 2 et 3 du résolveur, et les jobs
 * qui envoient le jeton PERSONNEL d'un compte (`SyncUserClasses`, `SyncKlassciClasse`).
 *
 * Le cas visé : une école passée en autonome (ou désactivée) qui a GARDÉ son URL et son
 * jeton. Elle ne doit plus être ciblée, ni par son jeton d'école ni par le jeton d'un
 * compte. Et un jeton personnel ne part jamais vers une autre adresse que celle qui l'a
 * émis, même depuis un job.
 *
 * Seule la frontière réseau est feinte : une closure relève CHAQUE requête émise.
 */
final class ToutesLesCiblesLisentLeModeTest extends TestCase
{
    use RefreshDatabase;

    private const URL_ECOLE = 'https://klassci-ecole.tenant.test';

    private const AUTRE_URL = 'https://klassci-autre.tenant.test';

    private const JETON_ECOLE = 'jeton-de-l-ecole';

    private const JETON_DU_COMPTE = 'jeton-personnel-du-compte';

    private const URL_GLOBALE = 'https://klassci-global.test';

    /** @var list<array{url: string, autorisation: string}> */
    private array $envoyees = [];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.klassci.url', self::URL_GLOBALE);
        Config::set('services.klassci.token', 'jeton-global');

        $factory = new HttpFactory;
        $factory->fake(function (ClientRequest $requete) {
            $this->envoyees[] = ['url' => $requete->url(), 'autorisation' => $requete->header('Authorization')[0] ?? ''];

            return Http::response(['success' => true, 'data' => []]);
        });
        $this->app->instance(HttpFactory::class, $factory);
    }

    // ── Priorité 2 : compte sans jeton personnel, à l'adresse de son école ──

    public function test_priorite_2_une_ecole_klassci_prete_son_jeton(): void
    {
        $ecole = $this->ecole();
        $this->contexte($this->compteSansJeton($ecole), $ecole);

        self::assertSame([self::URL_ECOLE, self::JETON_ECOLE], $this->cible());
    }

    /** @param  array<string, mixed>  $detachement */
    #[DataProvider('detachements')]
    public function test_priorite_2_une_ecole_detachee_ne_prete_plus_son_jeton(array $detachement): void
    {
        $ecole = $this->ecole($detachement);
        $this->contexte($this->compteSansJeton($ecole), $ecole);

        self::assertSame([null, null], $this->cible());
    }

    // ── Priorité 3 : le tenant seul, sans compte (console, file, route publique) ──

    public function test_priorite_3_une_ecole_klassci_garde_sa_cible(): void
    {
        $this->contexte(null, $this->ecole());

        self::assertSame([self::URL_ECOLE, self::JETON_ECOLE], $this->cible());
    }

    /** @param  array<string, mixed>  $detachement */
    #[DataProvider('detachements')]
    public function test_priorite_3_une_ecole_detachee_n_a_plus_de_cible_et_rien_ne_part(array $detachement): void
    {
        $this->contexte(null, $this->ecole($detachement));

        self::assertSame([null, null], $this->cible());

        try {
            app(KlassciProxyService::class)->get('classes');
        } catch (KlassciUnavailableException) {
            // Échec bruyant attendu (#270) : l'essentiel est qu'aucune requête ne parte.
        }
        self::assertSame([], $this->envoyees);
    }

    /** Sans tenant (supradmin, route publique) : la config globale reste, comme depuis #792. */
    public function test_sans_tenant_la_config_globale_reste(): void
    {
        $this->app->forgetScopedInstances();

        self::assertSame([self::URL_GLOBALE, 'jeton-global'], $this->cible());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function detachements(): array
    {
        return [
            'autonome, URL et jeton gardés' => [['mode' => InstitutionMode::Standalone]],
            'inactive, URL et jeton gardés' => [['is_active' => false]],
        ];
    }

    // ── Les jobs : le jeton PERSONNEL ne part que vers la cible du compte ──

    #[DataProvider('jobs')]
    public function test_job_un_compte_rattache_synchronise_avec_son_jeton(string $job): void
    {
        $ecole = $this->ecole();

        $this->lancer($job, $this->compteAJeton($ecole, self::URL_ECOLE), $ecole);

        self::assertNotSame([], $this->envoyees);
        foreach ($this->envoyees as $requete) {
            self::assertStringStartsWith(self::URL_ECOLE, $requete['url']);
            self::assertSame('Bearer '.self::JETON_DU_COMPTE, $requete['autorisation']);
        }
    }

    #[DataProvider('jobs')]
    public function test_job_une_ecole_autonome_qui_garde_son_url_ne_recoit_rien(string $job): void
    {
        $ecole = $this->ecole(['mode' => InstitutionMode::Standalone]);

        $this->lancer($job, $this->compteAJeton($ecole, self::URL_ECOLE), $ecole);

        self::assertSame([], $this->envoyees);
    }

    /** Le fait B de #1248, côté file : le jeton émis par X ne part pas chez Y. */
    #[DataProvider('jobs')]
    public function test_job_le_jeton_d_une_adresse_ne_part_pas_vers_l_url_de_l_ecole(string $job): void
    {
        $ecole = $this->ecole();

        $this->lancer($job, $this->compteAJeton($ecole, self::AUTRE_URL), $ecole);

        self::assertSame([], $this->envoyees);
    }

    /** Un job mis en file pour une AUTRE école que celle du compte : rien ne part. */
    #[DataProvider('jobs')]
    public function test_job_mis_en_file_pour_une_autre_ecole_ne_recoit_rien(string $job): void
    {
        $sienne = $this->ecole();
        $autre = $this->ecole(['klassci_api_url' => self::AUTRE_URL]);

        $this->lancer($job, $this->compteAJeton($sienne, self::URL_ECOLE), $autre);

        self::assertSame([], $this->envoyees);
    }

    /** @return array<string, array{string}> */
    public static function jobs(): array
    {
        return ['SyncUserClasses' => [SyncUserClasses::class], 'SyncKlassciClasse' => [SyncKlassciClasse::class]];
    }

    private function lancer(string $job, User $compte, Institution $ecole): void
    {
        $this->app->forgetScopedInstances();
        $this->app['auth']->forgetGuards();
        $instance = $job === SyncUserClasses::class
            ? new SyncUserClasses($compte->id, $ecole->id)
            : new SyncKlassciClasse(55, $compte->id, $ecole->id);

        $this->app->call([$instance, 'handle']);
    }

    /** @return array{0: string|null, 1: string|null} */
    private function cible(): array
    {
        $resolveur = app(KlassciConfigResolver::class);

        return [$resolveur->baseUrl(), $resolveur->token()];
    }

    /** Le contexte d'une requête, sans requête : compte connecté (ou aucun) et tenant résolu. */
    private function contexte(?User $compte, Institution $ecole): void
    {
        $this->app->forgetScopedInstances();
        app(TenantManager::class)->set($ecole);
        if ($compte instanceof User) {
            $this->app['auth']->guard('sanctum')->setUser($compte);
        }
    }

    /** @param  array<string, mixed>  $attributs */
    private function ecole(array $attributs = []): Institution
    {
        return Institution::factory()->create($attributs + [
            'klassci_api_url' => self::URL_ECOLE,
            'klassci_api_token_encrypted' => self::JETON_ECOLE,
            'mode' => InstitutionMode::Klassci,
            'is_active' => true,
        ]);
    }

    private function compteSansJeton(Institution $ecole): User
    {
        return User::factory()->for($ecole)->create([
            'role' => 'coordinateur', 'klassci_token' => null, 'klassci_tenant_url' => self::URL_ECOLE,
        ]);
    }

    private function compteAJeton(Institution $ecole, string $adresse): User
    {
        return User::factory()->for($ecole)->create([
            'role' => 'enseignant', 'klassci_token' => self::JETON_DU_COMPTE, 'klassci_tenant_url' => $adresse,
        ]);
    }
}
