<?php

declare(strict_types=1);

namespace Tests\Feature\Klassci;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\KlassciConfigResolver;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1331 — une école saisie avec un « / » final garde la liaison de ses comptes.
 *
 * La connexion stocke dans `users.klassci_tenant_url` l'URL de l'école rognée de son
 * « / » final (`LoginOrchestrator`, `KlassciTenantDiscovery`). Le résolveur la comparait,
 * avec `===`, à l'URL BRUTE de l'école : les priorités 1 et 2 ne trouvaient jamais leur
 * cible (refus absorbant, 503). Et le client HTTP ajoutait `/endpoint` à l'URL brute :
 * `…/api/lms//seances`.
 *
 * L'URL de l'école est désormais canonique dès l'écriture. Les comptes sont posés ici
 * comme la connexion les écrit, adresse rognée.
 *
 * Seule la frontière réseau est feinte, une closure par requête, qui relève CHAQUE
 * requête émise ; le middleware, le proxy et le résolveur sont réels.
 */
final class UrlKlassciCanoniqueTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const ADRESSE = 'https://klassci-x.tenant.test/api/lms';

    private const JETON_DU_COMPTE = 'jeton-personnel-du-compte';

    private const JETON_DE_L_ECOLE = 'jeton-systeme-de-l-ecole';

    /** Une route du groupe `klassci.sync` qui n'appelle pas KLASSCI elle-même. */
    private const ROUTE_SYNCHRONISEE = '/api/chapters/999999';

    /** @var list<array{url: string, autorisation: string}> */
    private array $envoyees = [];

    private ?int $klassciIdDuCompte = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reseauRelevePuisRepond();
    }

    /** Priorité 1 : le jeton personnel retrouve sa cible, par le vrai middleware. */
    public function test_le_jeton_personnel_retrouve_l_ecole_saisie_avec_un_slash_final(): void
    {
        $compte = $this->comptePerimeDans($this->ecole(self::ADRESSE.'/'));

        $this->asTenant($compte)->getJson(self::ROUTE_SYNCHRONISEE);

        self::assertSame([['url' => self::ADRESSE.'/auth/me', 'autorisation' => 'Bearer '.self::JETON_DU_COMPTE]], $this->envoyees);
        self::assertSame('Nom KLASSCI', $compte->refresh()->name);
    }

    /** Priorité 2 : un compte sans jeton personnel parle par le jeton de son école, sans double slash. */
    public function test_le_jeton_de_l_ecole_part_vers_l_adresse_canonique(): void
    {
        $ecole = $this->ecole(self::ADRESSE.'/');
        $compte = User::factory()->for($ecole)->create(['klassci_token' => null, 'klassci_tenant_url' => self::ADRESSE]);
        $this->connecteSansRequete($ecole, $compte);

        $resolveur = app(KlassciConfigResolver::class);
        self::assertSame(self::ADRESSE, $resolveur->baseUrl());
        self::assertSame(self::JETON_DE_L_ECOLE, $resolveur->token());

        app(KlassciProxyService::class)->get('seances');
        self::assertSame([['url' => self::ADRESSE.'/seances', 'autorisation' => 'Bearer '.self::JETON_DE_L_ECOLE]], $this->envoyees);
    }

    /** Priorité 3 : l'école résolue, sans compte, parle sans double slash. */
    public function test_l_ecole_resolue_parle_sans_double_slash(): void
    {
        $this->connecteSansRequete($this->ecole(self::ADRESSE.'/'), null);

        app(KlassciProxyService::class)->get('seances');

        self::assertSame([['url' => self::ADRESSE.'/seances', 'autorisation' => 'Bearer '.self::JETON_DE_L_ECOLE]], $this->envoyees);
    }

    /** Verrou : une école autonome garde son URL, rognée, mais n'a toujours aucune cible. */
    public function test_une_ecole_autonome_ne_parle_toujours_pas_a_klassci(): void
    {
        $ecole = $this->ecole(self::ADRESSE.'/', InstitutionMode::Standalone);
        $compte = $this->comptePerimeDans($ecole);

        $this->asTenant($compte)->getJson(self::ROUTE_SYNCHRONISEE);

        self::assertSame([], $this->envoyees);
    }

    /** @return array<string, array{0: string, 1: InstitutionMode, 2: string}> */
    public static function saisies(): array
    {
        return [
            'création, école KLASSCI, un slash' => ['POST', InstitutionMode::Klassci, self::ADRESSE.'/'],
            'création, école autonome, un slash' => ['POST', InstitutionMode::Standalone, self::ADRESSE.'/'],
            'modification, plusieurs slashs' => ['PUT', InstitutionMode::Klassci, self::ADRESSE.'///'],
            'verrou : sans slash, inchangée' => ['POST', InstitutionMode::Klassci, self::ADRESSE],
        ];
    }

    /** L'écran du supradmin écrit l'URL canonique, sur les deux modes. */
    #[DataProvider('saisies')]
    public function test_l_url_saisie_est_enregistree_sans_slash_final(string $verbe, InstitutionMode $mode, string $saisie): void
    {
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);
        $entetes = ['Authorization' => 'Bearer '.$supradmin->createToken('1331')->plainTextToken];

        $id = $verbe === 'POST'
            ? $this->postJson('/api/admin/institutions', ['slug' => 'ecole-1331', 'name' => 'École 1331', 'mode' => $mode->value, 'klassci_api_url' => $saisie], $entetes)
                ->assertCreated()->json('data.id')
            : $this->putJson('/api/admin/institutions/'.$this->ecole(self::ADRESSE, $mode)->id, ['klassci_api_url' => $saisie], $entetes)
                ->assertOk()->json('data.id');

        self::assertIsInt($id);
        self::assertSame(self::ADRESSE, Institution::query()->findOrFail($id)->klassci_api_url);
    }

    private function ecole(string $url, InstitutionMode $mode = InstitutionMode::Klassci): Institution
    {
        return Institution::factory()->create([
            'klassci_api_url' => $url,
            'klassci_api_token' => self::JETON_DE_L_ECOLE,
            'mode' => $mode,
            'is_active' => true,
        ]);
    }

    /** Un compte rattaché, posé comme la connexion l'écrit : adresse rognée, synchronisation périmée. */
    private function comptePerimeDans(Institution $ecole): User
    {
        $compte = User::factory()->for($ecole)->create([
            'role' => 'enseignant',
            'klassci_role' => 'enseignant',
            'name' => 'Nom Avant',
            'klassci_data' => ['nom' => 'Nom Avant'],
            'klassci_token' => self::JETON_DU_COMPTE,
            'klassci_tenant_url' => self::ADRESSE,
            'last_klassci_sync' => now()->subHours(25),
        ]);
        $this->klassciIdDuCompte = $compte->klassci_id;

        return $compte;
    }

    /** Le contexte d'une requête, sans requête : école résolue, et compte connecté s'il y en a un. */
    private function connecteSansRequete(Institution $ecole, ?User $compte): void
    {
        $this->app->forgetScopedInstances();
        app(TenantManager::class)->set($ecole);
        if ($compte instanceof User) {
            $this->app['auth']->guard('sanctum')->setUser($compte);
        }
    }

    /**
     * Feinte de la seule frontière réseau, une closure par requête : chaque requête est
     * relevée (adresse et jeton porteur) ; `auth/me` rend la forme réelle, le reste vide.
     */
    private function reseauRelevePuisRepond(): void
    {
        $factory = new HttpFactory;
        $factory->fake(function (ClientRequest $requete) {
            $this->envoyees[] = ['url' => $requete->url(), 'autorisation' => $requete->header('Authorization')[0] ?? ''];
            if (! str_contains($requete->url(), 'auth/me')) {
                return Http::response(['success' => true, 'data' => []]);
            }

            return Http::response(['success' => true, 'data' => ['id' => $this->klassciIdDuCompte, 'nom' => 'Nom KLASSCI', 'role' => 'enseignant']]);
        });

        $this->app->instance(HttpFactory::class, $factory);
    }
}
