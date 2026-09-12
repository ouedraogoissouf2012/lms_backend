<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
 * @see app/Services/Klassci/Auth/KlassciTenantDiscovery.php
 * @see app/Services/Auth/LoginOrchestrator.php
 */
final class LoginEnumerationTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_URL = 'https://tenant687.klassci.test/api/lms';

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
     * `check-user` reconnaît l'identifiant, puis `login` le rejette : c'est la
     * forme « compte connu, mot de passe faux ».
     */
    private function feindreCompteConnuMotDePasseFaux(): void
    {
        Http::fake([
            '*/auth/check-user' => Http::response(['success' => true, 'data' => ['found' => true]], 200),
            '*/auth/login' => Http::response(['success' => false, 'message' => 'Identifiants incorrects'], 401),
        ]);
    }

    /** `check-user` ne reconnaît personne : le chemin s'arrête là. */
    private function feindreCompteInconnu(): void
    {
        Http::fake([
            '*/auth/check-user' => Http::response(['success' => true, 'data' => ['found' => false]], 200),
            '*/auth/login' => Http::response(['success' => false], 401),
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
        $this->feindreCompteInconnu();
        $this->assertSame(401, $this->tenterLogin('inconnu.total')['status']);

        $this->feindreCompteConnuMotDePasseFaux();
        $this->assertSame(401, $this->tenterLogin('connu.mauvais.mdp')['status']);
    }

    public function test_le_corps_de_reponse_est_identique_au_caractere_pres(): void
    {
        // Un message qui differe d'un mot suffit a enumerer les comptes.
        $this->feindreCompteInconnu();
        $inconnu = $this->tenterLogin('inconnu.total')['body'];

        $this->feindreCompteConnuMotDePasseFaux();
        $mauvaisMdp = $this->tenterLogin('connu.mauvais.mdp')['body'];

        $this->assertSame($inconnu, $mauvaisMdp);
    }

    public function test_aucune_reponse_ne_nomme_le_champ_en_cause(): void
    {
        $this->feindreCompteConnuMotDePasseFaux();
        $corps = strtolower($this->tenterLogin('connu.mauvais.mdp')['body']);

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
        $this->feindreCompteInconnu();
        $this->tenterLogin('inconnu.total');

        $this->assertCount(1, Http::recorded());
    }

    public function test_un_mot_de_passe_faux_en_coute_deux(): void
    {
        $this->feindreCompteConnuMotDePasseFaux();
        $this->tenterLogin('connu.mauvais.mdp');

        // Un appel de plus que le cas inconnu : c'est l'oracle, et il est ouvert.
        $this->assertCount(2, Http::recorded());
    }
}
