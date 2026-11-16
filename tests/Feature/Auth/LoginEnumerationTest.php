<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #687 — « compte inconnu » et « mot de passe erroné » doivent être
 * indistinguables, message ET travail accompli.
 *
 * ## Pourquoi compter les appels plutôt que chronométrer
 *
 * Le critère de l'issue parle de « temps de réponse identiques ». Un chronomètre
 * dans une suite de tests mesure surtout la charge de la machine : il rend vert
 * un vrai canal auxiliaire un jour de calme, et rouge un code sain un jour de
 * CI chargée.
 *
 * Le temps, ici, n'est que la conséquence observable d'une différence
 * structurelle : un aller-retour HTTP supplémentaire vers KLASSCI. On mesure
 * donc la CAUSE — le nombre de requêtes sortantes — qui est déterministe et dit
 * exactement la même chose.
 *
 * ## Ce que le chemin fait aujourd'hui
 *
 * `KlassciTenantDiscovery::findMatchingTenants()` interroge `/auth/check-user`
 * sur TOUS les tenants actifs, en parallèle. Ensuite, et seulement pour les
 * tenants qui ont reconnu l'identifiant, `KlassciAuthClient::attemptLogin()`
 * envoie un `POST /auth/login`.
 *
 * Un identifiant inconnu s'arrête donc après la première salve. Un identifiant
 * connu avec un mauvais mot de passe paie une requête de plus.
 *
 * ## L'oracle LOCAL, refermé (#687)
 *
 * Avant KLASSCI, `LocalLmsAuthenticator` vérifie le mot de passe des comptes
 * locaux : un compte connu (le supradmin, tout compte déjà synchronisé) coûtait
 * UNE vérification bcrypt, un identifiant inconnu AUCUNE. Mesuré le 2026-09-26 :
 * ~519 ms contre ~23 ms. Ce chronomètre-là se lit à travers la gigue de KLASSCI
 * en moyennant. Un échec coûte désormais une vérification dans les deux cas, la
 * seconde contre une empreinte leurre (`EmpreinteLeurre`). Là encore on compte
 * la CAUSE, les vérifications du hacheur, et non des millisecondes.
 *
 * @see app/Services/Klassci/Auth/KlassciTenantDiscovery.php
 * @see app/Services/Auth/LoginOrchestrator.php
 */
final class LoginEnumerationTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_URL = 'https://tenant687.klassci.test/api/lms';

    /** L'identifiant que KLASSCI reconnaît, et dont il refuse le mot de passe. */
    private const CONNU_DE_KLASSCI = 'connu.mauvais.mdp';

    /** Un compte LOCAL, dont le mot de passe proposé est faux. */
    private const CONNU_EN_LOCAL = 'local.connu';

    protected function setUp(): void
    {
        parent::setUp();

        Institution::factory()->create([
            'slug' => 'tenant687',
            'is_active' => true,
            'klassci_api_url' => self::TENANT_URL,
        ]);
    }

    /**
     * KLASSCI, feint UNE fois pour tout le test : `check-user` ne reconnaît que
     * `CONNU_DE_KLASSCI`, et `login` le rejette (« compte connu, mot de passe
     * faux ») ; tout autre identifiant s'arrête à `check-user`.
     *
     * Une seule feinte, qui répond selon la REQUÊTE : deux `Http::fake()` dans
     * une même méthode s'empilent, et le PREMIER gagne. Deux tests de ce
     * fichier en empilaient deux : leur second login suivait le chemin du
     * premier, et l'égalité qu'ils affirmaient était vraie à vide.
     */
    private function feindreKlassci(): void
    {
        Http::fake(static function (Request $requete) {
            if (str_ends_with($requete->url(), '/auth/check-user')) {
                return Http::response(['success' => true, 'data' => ['found' => $requete['identifier'] === self::CONNU_DE_KLASSCI]], 200);
            }

            return Http::response(['success' => false, 'message' => 'Identifiants incorrects'], 401);
        });
    }

    /**
     * Le hacheur de l'application, qui compte ses vérifications.
     *
     * @return object{verifications: int}
     */
    private function compterLesVerifications(): object
    {
        $reel = $this->app->make(Hasher::class);
        $compteur = new class($reel) implements Hasher
        {
            public int $verifications = 0;

            public function __construct(private readonly Hasher $reel) {}

            /** @return array<string, mixed> */
            public function info($hashedValue): array
            {
                return $this->reel->info($hashedValue);
            }

            /** @param  array<string, mixed>  $options */
            public function make(#[\SensitiveParameter] $value, array $options = []): string
            {
                return $this->reel->make($value, $options);
            }

            /** @param  array<string, mixed>  $options */
            public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
            {
                $this->verifications++;

                return $this->reel->check($value, $hashedValue, $options);
            }

            /** @param  array<string, mixed>  $options */
            public function needsRehash($hashedValue, array $options = []): bool
            {
                return $this->reel->needsRehash($hashedValue, $options);
            }
        };
        $this->app->instance(Hasher::class, $compteur);

        return $compteur;
    }

    /** Un compte local nommé `CONNU_EN_LOCAL`, dans le volet donné. */
    private function compteLocal(string $volet): void
    {
        $ecole = match ($volet) {
            'supradmin' => null,
            'ecole_klassci' => Institution::query()->where('slug', 'tenant687')->firstOrFail(),
            'ecole_autonome' => Institution::factory()->standalone()->create(),
        };

        User::factory()->create([
            'name' => self::CONNU_EN_LOCAL,
            'email' => 'local.connu@ecole687.ci',
            'password' => 'le-vrai-mot-de-passe',
            'institution_id' => $ecole?->id,
            'role' => $ecole === null ? 'supradmin' : 'enseignant',
        ]);
    }

    /**
     * @return array{status: int, body: string}
     */
    private function tenterLogin(string $username): array
    {
        $reponse = $this->postJson('/api/auth/login', [
            'username' => $username,
            'password' => 'mot-de-passe-quelconque',
        ]);

        return ['status' => $reponse->status(), 'body' => $reponse->getContent() ?: ''];
    }

    public function test_les_deux_cas_rendent_401(): void
    {
        $this->feindreKlassci();
        $this->assertSame(401, $this->tenterLogin('inconnu.total')['status']);
        $this->assertSame(401, $this->tenterLogin(self::CONNU_DE_KLASSCI)['status']);

        // Preuve du chemin : le second login a bien atteint `login`, le premier non.
        $this->assertCount(3, Http::recorded());
    }

    public function test_le_corps_de_reponse_est_identique_au_caractere_pres(): void
    {
        // Un message qui differe d'un mot suffit a enumerer les comptes.
        $this->feindreKlassci();
        $inconnu = $this->tenterLogin('inconnu.total')['body'];
        $mauvaisMdp = $this->tenterLogin(self::CONNU_DE_KLASSCI)['body'];

        $this->assertCount(3, Http::recorded(), 'Le second login doit avoir suivi le chemin « mot de passe faux ».');
        $this->assertSame($inconnu, $mauvaisMdp);
    }

    public function test_aucune_reponse_ne_nomme_le_champ_en_cause(): void
    {
        $this->feindreKlassci();
        $corps = strtolower($this->tenterLogin(self::CONNU_DE_KLASSCI)['body']);

        foreach (['introuvable', 'inconnu', 'not found', 'utilisateur n', 'mot de passe incorrect'] as $fuite) {
            $this->assertStringNotContainsString($fuite, $corps);
        }
    }

    /**
     * Le canal auxiliaire de l'issue — MESURÉ, et toujours ouvert.
     *
     * Ce test ne vérifie pas une égalité : il FIGE l'asymétrie constatée, pour
     * qu'elle ne puisse ni s'aggraver ni disparaître en silence.
     *
     * Mesuré le 2026-09-12, en instrumentant les URL réellement émises :
     *
     *     compte inconnu      → 1 appel   POST .../auth/check-user
     *     mot de passe faux   → 2 appels  POST .../auth/check-user
     *                                     POST .../auth/login
     *
     * L'aller-retour supplémentaire est directement mesurable côté client : il
     * permet d'énumérer les comptes sans jamais deviner un mot de passe. Le
     * débit est plafonné à 10 req/min (`routes/api/core.php:46`), ce qui borne
     * l'attaque sans la fermer — 14 400 sondes par jour et par clé.
     *
     * La refermer est une décision de conception, pas un correctif : chacune des
     * voies possibles a un coût réel (temps de travailleur immobilisé, ou envoi
     * d'identifiants à un tenant qui ne connaît pas le compte). Elle est donc
     * portée par #687 et non tranchée ici.
     *
     * ATTENTION au piège rencontré en écrivant ce fichier : `Http::fake()`
     * appelé DEUX FOIS dans la même méthode de test ne réapplique pas les
     * feintes — les deux cas empruntaient le même chemin et le test passait à
     * vide. D'où une feinte par méthode, et des comptes ABSOLUS assertés.
     */
    public function test_un_compte_inconnu_coute_un_seul_appel_sortant(): void
    {
        $this->feindreKlassci();
        $this->tenterLogin('inconnu.total');

        $this->assertCount(1, Http::recorded());
    }

    public function test_un_mot_de_passe_faux_en_coute_deux(): void
    {
        $this->feindreKlassci();
        $this->tenterLogin(self::CONNU_DE_KLASSCI);

        // Un appel de plus que le cas inconnu : c'est l'oracle, et il est ouvert.
        $this->assertCount(2, Http::recorded());
    }

    /**
     * L'oracle LOCAL de #687 : un compte local avec un mauvais mot de passe et un
     * identifiant inconnu coûtent chacun UNE vérification. Avant : un contre zéro.
     *
     * Trois volets : le supradmin, seul compte purement local et le plus
     * précieux à énumérer ; un compte d'une école KLASSCI ; un compte d'une école
     * autonome. Le login précède le tenant : aucun ne doit différer.
     */
    #[DataProvider('volets')]
    public function test_un_echec_coute_une_verification_que_le_compte_existe_ou_non(string $volet): void
    {
        $this->feindreKlassci();
        $this->compteLocal($volet);
        $compteur = $this->compterLesVerifications();

        $this->assertSame(401, $this->tenterLogin(self::CONNU_EN_LOCAL)['status']);
        $motDePasseFaux = $compteur->verifications;
        $compteur->verifications = 0;

        $this->assertSame(401, $this->tenterLogin('local.inconnu')['status']);

        $this->assertSame(1, $motDePasseFaux, 'Mot de passe faux : la vérification du vrai compte.');
        $this->assertSame(1, $compteur->verifications, 'Identifiant inconnu : une vérification, contre le leurre.');
    }

    /** @return array<string, array{string}> */
    public static function volets(): array
    {
        return [
            'supradmin' => ['supradmin'],
            'école KLASSCI' => ['ecole_klassci'],
            'école autonome' => ['ecole_autonome'],
        ];
    }
}
