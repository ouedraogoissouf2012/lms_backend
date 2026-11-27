<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1739 — quand KLASSCI refuse la connexion d'un compte pour une raison CONCLUSIVE autre que le mot de passe (403 :
 * « pas encore réinscrit », compte inactif…), le LMS le DIT : 403 `klassci_account_refused`, avec le message de KLASSCI
 * nettoyé (balises retirées, espaces resserrés, longueur bornée). Avant, ce refus devenait un 401 « Identifiants
 * incorrects » : l'élève croyait son mot de passe faux, alors qu'il devait se faire réinscrire.
 *
 * Ce qui ne change pas (#687) : le 401 reste aux identifiants refusés, le 503 à la panne. L'ordre des issues sur
 * plusieurs écoles : une connexion réussie > un refus de compte (un fait sur CE compte) > une panne > le 401.
 * KLASSCI est simulé au niveau HTTP (Http::fake), comme dans PanneKlassciALaConnexionTest.
 */
final class RefusDeCompteKlassciTest extends TestCase
{
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const ECOLE_A = 'https://ecole-a.klassci.test/api/lms';

    private const ECOLE_B = 'https://ecole-b.klassci.test/api/lms';

    private const IDENTIFIANT = 'eleve.1739';

    private const REINSCRIPTION = "Vous n'êtes pas encore réinscrit pour l'année universitaire en cours. Veuillez contacter le secrétariat pour procéder à votre réinscription.";

    /** @var array<string, array{check: mixed, login: mixed}> */
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

    public function test_un_refus_de_compte_de_klassci_est_dit_avec_son_message(): void
    {
        $this->ecole(self::ECOLE_A, trouve: true, login: Http::response(['success' => false, 'message' => self::REINSCRIPTION], 403));
        $this->ecole(self::ECOLE_B, trouve: false);

        $reponse = $this->connexion()
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => self::REINSCRIPTION, 'reason' => 'klassci_account_refused']);

        $this->assertMatchesOpenApiResponse($reponse, '/auth/login', 'post');
        self::assertSame(0, User::query()->withoutGlobalScopes()->count(), 'Un compte refusé ne doit pas être créé.');
    }

    public function test_le_message_de_klassci_est_nettoye(): void
    {
        $brut = "<p><b>Compte  suspendu</b></p>\n\t<script>alert(1)</script> Contactez   la scolarité.";
        $this->ecole(self::ECOLE_A, trouve: true, login: Http::response(['message' => $brut], 403));
        $this->ecole(self::ECOLE_B, trouve: false);

        $this->connexion()->assertForbidden()->assertJsonPath('message', 'Compte suspendu Contactez la scolarité.');
    }

    public function test_un_message_demesure_est_borne(): void
    {
        $this->ecole(self::ECOLE_A, trouve: true, login: Http::response(['message' => str_repeat('a', 2000)], 403));
        $this->ecole(self::ECOLE_B, trouve: false);

        $message = $this->connexion()->assertForbidden()->json('message');
        self::assertIsString($message);
        self::assertLessThanOrEqual(300, mb_strlen($message));
    }

    public function test_un_refus_sans_message_dit_un_texte_de_repli(): void
    {
        $this->ecole(self::ECOLE_A, trouve: true, login: Http::response(['success' => false], 403));
        $this->ecole(self::ECOLE_B, trouve: false);

        $this->connexion()
            ->assertForbidden()
            ->assertJsonPath('reason', 'klassci_account_refused')
            ->assertJsonPath('message', 'Votre école refuse la connexion de ce compte. Contactez son secrétariat.');
    }

    public function test_des_identifiants_refuses_restent_un_401(): void
    {
        $this->ecole(self::ECOLE_A, trouve: true, login: Http::response(['success' => false, 'message' => 'Identifiants incorrects'], 401));
        $this->ecole(self::ECOLE_B, trouve: false);

        $this->connexion()->assertStatus(401)->assertExactJson(['success' => false, 'message' => 'Identifiants incorrects']);
    }

    public function test_une_panne_reste_un_503(): void
    {
        $this->ecole(self::ECOLE_A, trouve: true, login: Http::response(['message' => 'indisponible'], 500));
        $this->ecole(self::ECOLE_B, trouve: false);

        $this->connexion()->assertStatus(503);
    }

    public function test_le_refus_de_compte_l_emporte_sur_la_panne_d_une_autre_ecole(): void
    {
        $this->ecole(self::ECOLE_A, trouve: true, login: Http::response(['message' => self::REINSCRIPTION], 403));
        $this->ecole(self::ECOLE_B, trouve: true, login: Http::failedConnection('KLASSCI injoignable'));

        $this->connexion()->assertForbidden()->assertJsonPath('reason', 'klassci_account_refused');
    }

    public function test_une_connexion_reussie_ailleurs_l_emporte_sur_le_refus(): void
    {
        $this->ecole(self::ECOLE_A, trouve: true, login: Http::response(['message' => self::REINSCRIPTION], 403));
        $this->ecole(self::ECOLE_B, trouve: true, login: Http::response($this->succes(), 200));

        $this->connexion()->assertOk()->assertJsonPath('success', true);
    }

    public function test_le_refus_est_trace_a_l_audit(): void
    {
        $this->ecole(self::ECOLE_A, trouve: true, login: Http::response(['message' => self::REINSCRIPTION], 403));
        $this->ecole(self::ECOLE_B, trouve: false);

        $this->connexion()->assertForbidden();

        self::assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'login_refused_by_klassci')->count());
    }

    public function test_un_compte_local_lie_garde_son_repli_quand_klassci_refuse(): void
    {
        // Comportement inchangé : le compte local, authentifié par son mot de passe LMS, garde le repli local que #687
        // lui donne. Faut-il le refuser aussi ? Question ouverte, hors de cette issue (déclarée dans la PR).
        $ecole = Institution::query()->where('slug', 'ecole-a')->firstOrFail();
        User::factory()->for($ecole)->create([
            'email' => self::IDENTIFIANT.'@ecole-a.ci',
            'password' => 'le-bon-mot-de-passe',
            'klassci_id' => 1739002,
            'role' => 'enseignant',
        ]);
        $this->ecole(self::ECOLE_A, trouve: true, login: Http::response(['message' => self::REINSCRIPTION], 403));

        $this->postJson('/api/auth/login', ['username' => self::IDENTIFIANT.'@ecole-a.ci', 'password' => 'le-bon-mot-de-passe'])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    // ───────────────────────────────── harnais

    private function ecole(string $url, bool $trouve, mixed $login = null): void
    {
        $this->reponses[$url] = [
            'check' => Http::response(['success' => true, 'data' => ['found' => $trouve]], 200),
            'login' => $login ?? Http::response([], 404),
        ];
    }

    /** @return array<string, mixed> */
    private function succes(): array
    {
        return [
            'success' => true,
            'data' => [
                'user' => ['id' => 1739001, 'email' => 'eleve.1739@ecole-b.ci', 'nom' => 'Eleve 1739', 'role' => 'etudiant'],
                'token' => 'jeton-klassci-1739',
            ],
            'meta' => [],
        ];
    }

    private function connexion(): TestResponse
    {
        return $this->postJson('/api/auth/login', ['username' => self::IDENTIFIANT, 'password' => 'un-mot-de-passe']);
    }
}
