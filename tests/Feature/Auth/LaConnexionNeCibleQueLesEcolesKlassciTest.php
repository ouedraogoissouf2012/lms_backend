<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #1318 — la connexion n'envoie d'identifiant qu'aux écoles qui parlent à KLASSCI, par la
 * MÊME définition que #1248 et #1249 (`KlassciConfigResolver::cibleDeLEcole`) :
 *
 * - le rafraîchissement d'un compte lié (`LoginOrchestrator::refreshLinkedKlassciLogin`)
 *   envoie l'identifiant ET le mot de passe saisis ;
 * - le fan-out (`KlassciTenantDiscovery::loadActiveTenants`) envoie l'identifiant à chaque
 *   école, puis le mot de passe à celles qui l'ont reconnu.
 *
 * Le cas visé : une école passée en autonome, désactivée, ou dont l'URL n'est pas
 * exploitable, qui a GARDÉ son URL. Elle ne reçoit plus rien.
 *
 * Seule la frontière réseau est feinte : une closure relève CHAQUE requête émise.
 */
final class LaConnexionNeCibleQueLesEcolesKlassciTest extends TestCase
{
    use RefreshDatabase;

    private const URL_KLASSCI = 'https://klassci-ecole.tenant.test';

    private const URL_DETACHEE = 'https://klassci-detachee.tenant.test';

    private const IDENTIFIANT = 'enseignant@ecole.test';

    private const MOT_DE_PASSE = 'mot-de-passe-saisi';

    /** @var list<array{url: string, corps: array<array-key, mixed>}> */
    private array $envoyees = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Toute école reconnaît l'identifiant, et refuse le mot de passe : le fan-out va
        // donc jusqu'à `/auth/login`, et le rafraîchissement retombe sur la session locale.
        $factory = new HttpFactory;
        $factory->fake(function (ClientRequest $requete) {
            $this->envoyees[] = ['url' => $requete->url(), 'corps' => $requete->data()];

            return str_ends_with($requete->url(), '/auth/check-user')
                ? Http::response(['data' => ['found' => true]])
                : Http::response(['success' => false], 401);
        });
        $this->app->instance(HttpFactory::class, $factory);
    }

    // ── Rafraîchissement d'un compte lié, à la connexion locale ──

    public function test_rafraichissement_une_ecole_klassci_recoit_l_identifiant(): void
    {
        $this->compteLie($this->ecole(self::URL_KLASSCI));

        $this->connexion()->assertOk()->assertJsonPath('meta.klassci_synced', false);

        self::assertSame([self::URL_KLASSCI.'/auth/login'], array_column($this->envoyees, 'url'));
        self::assertSame(self::MOT_DE_PASSE, $this->envoyees[0]['corps']['password'] ?? null);
    }

    /** @param  array<string, mixed>  $detachement */
    #[DataProvider('detachements')]
    public function test_rafraichissement_le_mot_de_passe_ne_part_pas_vers_une_ecole_detachee(array $detachement): void
    {
        $this->compteLie($this->ecole(self::URL_DETACHEE, $detachement));

        // La connexion locale continue : seule la requête vers KLASSCI disparaît.
        $this->connexion()->assertOk()->assertJsonPath('meta.klassci_synced', false);

        self::assertSame([], $this->envoyees, 'Le mot de passe saisi est parti vers une école détachée.');
    }

    // ── Fan-out : un compte sans session locale ──

    public function test_fan_out_une_ecole_klassci_recoit_l_identifiant_puis_le_mot_de_passe(): void
    {
        $this->ecole(self::URL_KLASSCI);

        $this->connexion()->assertUnauthorized();

        self::assertSame(
            [self::URL_KLASSCI.'/auth/check-user', self::URL_KLASSCI.'/auth/login'],
            array_column($this->envoyees, 'url'),
        );
    }

    /** @param  array<string, mixed>  $detachement */
    #[DataProvider('detachements')]
    public function test_fan_out_une_ecole_detachee_ne_recoit_ni_l_identifiant_ni_le_mot_de_passe(array $detachement): void
    {
        $this->ecole(self::URL_KLASSCI);
        $this->ecole(self::URL_DETACHEE, $detachement);

        $this->connexion()->assertUnauthorized();

        // L'école KLASSCI, elle, est toujours interrogée : le fan-out n'est pas coupé.
        self::assertSame(
            [self::URL_KLASSCI.'/auth/check-user', self::URL_KLASSCI.'/auth/login'],
            array_column($this->envoyees, 'url'),
        );
    }

    /**
     * L'école a GARDÉ une URL : elle n'est détachée que par son mode, son état, ou une URL
     * qu'aucune requête ne devrait viser (sans hôte).
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function detachements(): array
    {
        return [
            'autonome, URL gardée' => [['mode' => InstitutionMode::Standalone]],
            'inactive, URL gardée' => [['is_active' => false]],
            'URL sans hôte' => [['klassci_api_url' => 'https:/klassci-sans-hote.test']],
        ];
    }

    private function connexion(): TestResponse
    {
        return $this->postJson('/api/auth/login', ['username' => self::IDENTIFIANT, 'password' => self::MOT_DE_PASSE]);
    }

    /** @param  array<string, mixed>  $attributs */
    private function ecole(string $url, array $attributs = []): Institution
    {
        return Institution::factory()->create($attributs + [
            'klassci_api_url' => $url,
            'mode' => InstitutionMode::Klassci,
            'is_active' => true,
        ]);
    }

    /** Un compte KLASSCI qui a aussi un mot de passe local : la connexion locale le rafraîchit. */
    private function compteLie(Institution $ecole): User
    {
        return User::factory()->for($ecole)->teacher()->create([
            'email' => self::IDENTIFIANT,
            'password' => Hash::make(self::MOT_DE_PASSE),
            'klassci_id' => 200001,
        ]);
    }
}
