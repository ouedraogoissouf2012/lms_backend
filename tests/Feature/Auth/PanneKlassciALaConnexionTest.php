<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #687 — pendant une panne de KLASSCI, un compte peut-être valide ne lit plus
 * « identifiants incorrects ».
 *
 * Seule une réponse CONCLUSIVE de KLASSCI produit un 401 (ADR-687-02). Une école qui
 * n'a rien dit (injoignable, 5xx, 408, 429, corps illisible) donne 503 si aucune autre
 * n'a conclu : sautée en silence, elle comptait pour « inconnu ».
 *
 * Vrai chemin HTTP : découverte, client de connexion et orchestrateur réels. Seule la
 * frontière réseau est feinte, par UNE closure qui répond selon l'hôte et la route.
 *
 * Volet autonome : une école autonome n'est jamais interrogée, et son compte local se
 * connecte comme avant (`LaConnexionNeCibleQueLesEcolesKlassciTest`, #1318).
 */
final class PanneKlassciALaConnexionTest extends TestCase
{
    use RefreshDatabase;

    private const ECOLE_A = 'https://ecole-a.klassci.test/api/lms';

    private const ECOLE_B = 'https://ecole-b.klassci.test/api/lms';

    private const IDENTIFIANT = 'compte.687';

    /** Le message mesuré de l'API KLASSCI de test, le 27/11 (#1739). */
    private const NON_REINSCRIT = "Vous n'êtes pas encore réinscrit pour l'année universitaire en cours. Veuillez contacter le secrétariat pour procéder à votre réinscription.";

    /** @var array<string, array{check: mixed, login: mixed}> réponses par école */
    private array $reponses = [];

    protected function setUp(): void
    {
        parent::setUp();
        Institution::factory()->create(['slug' => 'ecole-a', 'klassci_api_url' => self::ECOLE_A, 'is_active' => true]);
        Institution::factory()->create(['slug' => 'ecole-b', 'klassci_api_url' => self::ECOLE_B, 'is_active' => true]);

        Http::fake(function (Request $requete) {
            foreach ($this->reponses as $ecole => $reponse) {
                if (str_starts_with($requete->url(), $ecole.'/')) {
                    return str_ends_with($requete->url(), '/auth/check-user') ? $reponse['check'] : $reponse['login'];
                }
            }

            return Http::response([], 404);
        });
    }

    /** @return array<string, array{0: string}> */
    public static function decouvertesNonConclusives(): array
    {
        return [
            'A injoignable, B « inconnu »' => ['injoignable'],
            'A en 429 (filtre anti-rafale), B « inconnu »' => ['429'],
            'A en 5xx, B « inconnu »' => ['500'],
        ];
    }

    /** Une école muette peut porter le compte : sans correspondance ailleurs, 503. */
    #[DataProvider('decouvertesNonConclusives')]
    public function test_une_ecole_muette_a_la_decouverte_donne_503(string $panne): void
    {
        $this->ecoleA(check: $this->panne($panne), login: null);
        $this->ecoleB(check: $this->trouve(false), login: null);

        $this->connexion()->assertStatus(503)->assertJsonPath('success', false);
    }

    /** @return array<string, array{0: string}> */
    public static function connexionsIndeterminees(): array
    {
        return [
            'login injoignable' => ['injoignable'],
            'login en 5xx' => ['500'],
            'login en 429' => ['429'],
        ];
    }

    /** Un compte trouvé dont la connexion n'a pas été jugée : 503, jamais « identifiants incorrects ». */
    #[DataProvider('connexionsIndeterminees')]
    public function test_un_compte_trouve_dont_la_connexion_tombe_donne_503(string $panne): void
    {
        $this->ecoleA(check: $this->trouve(true), login: $this->panne($panne));
        $this->ecoleB(check: $this->trouve(false), login: null);

        $this->connexion()->assertStatus(503)->assertJsonPath('success', false);
    }

    /** Verrou : toutes les écoles concluent « inconnu » → 401, message neutre. */
    public function test_un_compte_inconnu_partout_reste_401(): void
    {
        $this->ecoleA(check: $this->trouve(false), login: null);
        $this->ecoleB(check: $this->trouve(false), login: null);

        $this->connexion()->assertStatus(401)->assertJsonPath('message', 'Identifiants incorrects');
    }

    /** Verrou : un refus conclusif de KLASSCI reste un mauvais mot de passe → 401. */
    public function test_un_refus_conclusif_reste_401(): void
    {
        $this->ecoleA(check: $this->trouve(true), login: Http::response(['success' => false, 'message' => 'Identifiants incorrects'], 401));
        $this->ecoleB(check: $this->trouve(false), login: null);

        $this->connexion()->assertStatus(401)->assertJsonPath('message', 'Identifiants incorrects');
    }

    /** Verrou : le compte trouvé sur B se connecte pendant que A est injoignable. */
    public function test_le_compte_trouve_se_connecte_malgre_une_autre_ecole_muette(): void
    {
        $this->ecoleA(check: $this->panne('injoignable'), login: null);
        $this->ecoleB(check: $this->trouve(true), login: Http::response($this->succesDeConnexion(), 200));

        $this->connexion()->assertOk()->assertJsonPath('success', true);
        self::assertTrue(User::query()->where('klassci_id', 687001)->exists());
    }

    /** Verrou : un compte local lié garde son repli local pendant une panne de KLASSCI. */
    public function test_un_compte_local_lie_garde_son_repli_pendant_une_panne(): void
    {
        $ecole = Institution::query()->where('slug', 'ecole-a')->firstOrFail();
        User::factory()->for($ecole)->create([
            'email' => self::IDENTIFIANT.'@ecole-a.ci',
            'password' => 'le-bon-mot-de-passe',
            'klassci_id' => 687002,
            'role' => 'enseignant',
        ]);
        $this->ecoleA(check: $this->trouve(true), login: $this->panne('injoignable'));

        $this->postJson('/api/auth/login', ['username' => self::IDENTIFIANT.'@ecole-a.ci', 'password' => 'le-bon-mot-de-passe'])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    // ───────────────────────── #1739 : le refus de compte de KLASSCI est dit

    /** Cas mesuré le 27/11 : un élève non réinscrit reçoit un 403 de KLASSCI, avec son motif. */
    public function test_un_refus_de_compte_est_dit_avec_le_message_de_klassci(): void
    {
        $this->ecoleA(check: $this->trouve(true), login: $this->refusDeCompte(self::NON_REINSCRIT));

        $this->connexion()
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('reason', 'klassci_account_refused')
            ->assertJsonPath('message', self::NON_REINSCRIT);
    }

    public function test_le_message_de_klassci_est_nettoye(): void
    {
        $brut = "  <b>Compte</b>\n\t suspendu <script>alert(1)</script>. ".str_repeat('x', 400);
        $this->ecoleA(check: $this->trouve(true), login: $this->refusDeCompte($brut));

        $message = $this->connexion()->assertStatus(403)->json('message');

        self::assertIsString($message);
        self::assertStringStartsWith('Compte suspendu alert(1). x', $message);
        self::assertStringNotContainsString('<', $message);
        self::assertLessThanOrEqual(300, mb_strlen($message));
    }

    public function test_un_refus_de_compte_sans_message_a_un_texte_a_nous(): void
    {
        $this->ecoleA(check: $this->trouve(true), login: Http::response(['success' => false], 403));

        $message = $this->connexion()->assertStatus(403)->assertJsonPath('reason', 'klassci_account_refused')->json('message');

        self::assertIsString($message);
        self::assertNotSame('', trim($message));
    }

    /** L'ordre : un succès l'emporte sur un refus de compte, qui l'emporte sur une panne, puis sur un 401. */
    public function test_un_succes_ailleurs_l_emporte_sur_un_refus_de_compte(): void
    {
        $this->ecoleA(check: $this->trouve(true), login: $this->refusDeCompte(self::NON_REINSCRIT));
        $this->ecoleB(check: $this->trouve(true), login: Http::response($this->succesDeConnexion(), 200));

        $this->connexion()->assertOk()->assertJsonPath('success', true);
    }

    public function test_un_refus_de_compte_l_emporte_sur_une_panne_et_sur_un_401(): void
    {
        $this->ecoleA(check: $this->trouve(true), login: $this->panne('503'));
        $this->ecoleB(check: $this->trouve(true), login: $this->refusDeCompte(self::NON_REINSCRIT));

        $this->connexion()->assertStatus(403)->assertJsonPath('reason', 'klassci_account_refused');

        $this->ecoleA(check: $this->trouve(true), login: Http::response(['success' => false, 'message' => 'Identifiants incorrects'], 401));

        $this->connexion()->assertStatus(403)->assertJsonPath('reason', 'klassci_account_refused');
    }

    /** Un compte local lié, déjà authentifié en local, garde son repli quand KLASSCI refuse le compte. */
    public function test_un_compte_local_lie_garde_son_repli_sur_un_refus_de_compte(): void
    {
        $ecole = Institution::query()->where('slug', 'ecole-a')->firstOrFail();
        User::factory()->for($ecole)->create([
            'email' => self::IDENTIFIANT.'@ecole-a.ci',
            'password' => 'le-bon-mot-de-passe',
            'klassci_id' => 687003,
            'role' => 'etudiant',
        ]);
        $this->ecoleA(check: $this->trouve(true), login: $this->refusDeCompte(self::NON_REINSCRIT));

        $this->postJson('/api/auth/login', ['username' => self::IDENTIFIANT.'@ecole-a.ci', 'password' => 'le-bon-mot-de-passe'])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    private function refusDeCompte(string $message): mixed
    {
        return Http::response(['success' => false, 'message' => $message], 403);
    }

    private function ecoleA(mixed $check, mixed $login): void
    {
        $this->reponses[self::ECOLE_A] = ['check' => $check, 'login' => $login ?? Http::response([], 404)];
    }

    private function ecoleB(mixed $check, mixed $login): void
    {
        $this->reponses[self::ECOLE_B] = ['check' => $check, 'login' => $login ?? Http::response([], 404)];
    }

    private function trouve(bool $trouve): mixed
    {
        return Http::response(['success' => true, 'data' => ['found' => $trouve]], 200);
    }

    private function panne(string $panne): mixed
    {
        return $panne === 'injoignable'
            ? Http::failedConnection('KLASSCI injoignable')
            : Http::response(['message' => 'indisponible'], (int) $panne);
    }

    /** @return array<string, mixed> */
    private function succesDeConnexion(): array
    {
        return [
            'success' => true,
            'data' => [
                'user' => ['id' => 687001, 'email' => 'compte.687@ecole-b.ci', 'nom' => 'Compte 687', 'role' => 'enseignant'],
                'token' => 'jeton-klassci-687',
            ],
            'meta' => [],
        ];
    }

    private function connexion(): TestResponse
    {
        return $this->postJson('/api/auth/login', ['username' => self::IDENTIFIANT, 'password' => 'un-mot-de-passe']);
    }
}
