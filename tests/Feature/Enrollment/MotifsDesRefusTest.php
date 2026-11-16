<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Models\User;
use App\Services\Enrollment\RejoindreParCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\PreparesClasseWithCode;
use Tests\TestCase;

/**
 * #906 — chaque refus d'inscription porte un MOTIF que le front peut lire.
 *
 * ## Le manque
 *
 * Le chemin central d'erreur du front (`normalizeError`) n'affiche pas le
 * message du serveur (hors 422) : il traduit un STATUT par son propre
 * catalogue. Or un même statut portait ici plusieurs sens —
 * « connectez-vous » et « votre inscription n'est pas active » sont deux 409 ;
 * « attendez une minute » et « votre école est bloquée jusqu'à demain », deux
 * 429. Le 409 finissait en « erreur inattendue », et l'apprenant chez le support.
 *
 * ## Ce que ce fichier garde
 *
 * Qu'à chaque sens correspond UN motif stable, dans le CORPS — jamais dans un
 * en-tête : le front de production est cross-origin, et le CORS ne lui expose
 * que des en-têtes de valeur (`Retry-After`, `X-RateLimit-*`) ; la classe d'une
 * erreur ne dépend jamais de la configuration CORS.
 *
 * Et qu'un 429 reste un 429 : le mécanisme de Laravel qui porte le motif d'un
 * seau lève une `HttpResponseException`, que le rendu générique de
 * l'application transformait en 500 — mesuré avant ce ticket.
 *
 * @see docs/adr/2026-09-26-906-01-le-motif-des-refus.md
 */
final class MotifsDesRefusTest extends TestCase
{
    use ActsAsTenantUser;
    use PreparesClasseWithCode;
    use RefreshDatabase;

    private const ANONYME = '/api/inscriptions';

    private const AUTHENTIFIEE = '/api/me/inscriptions';

    // Aucun nettoyage des seaux ici : `Tests\TestCase::setUp()` vide le store
    // Redis avant chaque test (#374), et `RefreshDatabase` annule la table
    // `cache` en mode database. Chaque test part de compteurs à zéro.

    // ───────────────────────── la porte anonyme ne dit pas si un compte existe (#914)

    public function test_porte_anonyme_un_compte_existant_ne_porte_aucun_motif(): void
    {
        [$ecole, $code] = $this->classeAvecCode();
        User::factory()->create(['institution_id' => $ecole->getKey(), 'email' => 'awa@test.ci']);

        $this->postJson(self::ANONYME, $this->candidature($code), ['X-Institution' => (string) $ecole->slug])
            ->assertStatus(202)
            ->assertJsonMissingPath('reason');
    }

    public function test_porte_authentifiee_une_adhesion_close_dit_inscription_non_active(): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $apprenant = $this->apprenant($ecole);
        DB::table('classe_etudiant')->insert([
            'classe_id' => $classe->getKey(),
            'user_id' => $apprenant->getKey(),
            'institution_id' => $ecole->getKey(),
            'statut' => 'suspendu',
            'date_inscription' => '2026-01-10',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->asTenant($apprenant)->postJson(self::AUTHENTIFIEE, ['code' => $code])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'enrolment_not_active');
    }

    public function test_porte_authentifiee_un_compte_sans_ecole_le_dit(): void
    {
        [, $code] = $this->classeAvecCode();
        $sansEcole = User::factory()->create(['institution_id' => null, 'role' => 'etudiant']);

        // La porte des comptes sans établissement répond la première, en 403, avec
        // le même motif ; le 409 de RejoindreParCodeService reste derrière elle,
        // en défense en profondeur (ADR-906-01).
        $this->asTenant($sansEcole)->postJson(self::AUTHENTIFIEE, ['code' => $code])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'no_institution');
    }

    public function test_seule_la_porte_authentifiee_rend_un_409(): void
    {
        // #906 distinguait deux 409 par leur motif. Depuis #914, la porte anonyme
        // n'en rend plus : un compte existant y reçoit la réponse d'un compte neuf.
        [$ecole, $code, $classe] = $this->classeAvecCode();
        User::factory()->create(['institution_id' => $ecole->getKey(), 'email' => 'awa@test.ci']);
        $anonyme = $this->postJson(self::ANONYME, $this->candidature($code), ['X-Institution' => (string) $ecole->slug]);

        // Une ligne COMPLÈTE, comme celles que les portes écrivent : établissement
        // et date d'inscription compris (ADR-711-02).
        $apprenant = $this->apprenant($ecole, 'autre@test.ci');
        $classe->etudiants()->attach($apprenant->id, [
            'institution_id' => $ecole->getKey(),
            'statut' => 'abandonne',
            'date_inscription' => '2026-01-10',
        ]);
        $authentifiee = $this->asTenant($apprenant)->postJson(self::AUTHENTIFIEE, ['code' => $code]);

        // Statuts ET motifs exacts : comparer deux motifs sans les nommer
        // resterait vert si l'une des deux portes rendait un 500 sans motif.
        $anonyme->assertStatus(202)->assertJsonMissingPath('reason');
        $authentifiee->assertStatus(409)->assertJsonPath('reason', 'enrolment_not_active');
    }

    public function test_un_refus_sans_motif_ne_change_pas_de_forme(): void
    {
        // Un code inconnu n'a qu'un sens — et le même message que le code
        // retiré, volontairement. Aucune clé `reason` ne lui est ajoutée : la
        // forme d'un refus qui n'en porte pas reste celle d'avant #906.
        [$ecole] = $this->classeAvecCode();

        $this->asTenant($this->apprenant($ecole))->postJson(self::AUTHENTIFIEE, ['code' => 'ZZZZZZ'])
            ->assertStatus(404)
            ->assertExactJson([
                'success' => false,
                'message' => 'Ce code ne correspond à aucune inscription ouverte.',
            ]);
    }

    // ───────────────────────── les 429 : quel budget, et toujours un 429

    public function test_porte_authentifiee_le_quota_du_compte(): void
    {
        [$ecole] = $this->classeAvecCode();
        $apprenant = $this->apprenant($ecole);

        for ($i = 0; $i < 10; $i++) {
            $this->asTenant($apprenant)->postJson(self::AUTHENTIFIEE, ['code' => 'ZZZZZZ']);
        }

        $this->unVrai429($this->asTenant($apprenant)->postJson(self::AUTHENTIFIEE, ['code' => 'ZZZZZZ']))
            ->assertJsonPath('reason', 'account_quota_exceeded');
    }

    public function test_porte_authentifiee_le_quota_journalier_du_compte_a_son_propre_motif(): void
    {
        // Le seau du compte a DEUX bornes, et elles n'appellent pas le même
        // message : « réessayez dans une minute » n'est pas « revenez demain ».
        // Même règle que la porte anonyme : `*_quota_exceeded` pour la minute,
        // `*_cap_reached` pour le jour (#924). Remplie sous la clé que
        // `ThrottleRequests:134` lui donne.
        [$ecole] = $this->classeAvecCode();
        $apprenant = $this->apprenant($ecole);

        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit($this->cleDeSeau('rejoindre-classe', 'rejoindre-jour|user:'.$apprenant->id), 86_400);
        }

        $this->unVrai429($this->asTenant($apprenant)->postJson(self::AUTHENTIFIEE, ['code' => 'ZZZZZZ']))
            ->assertJsonPath('reason', 'account_daily_cap_reached');
    }

    public function test_porte_authentifiee_le_plafond_de_l_ecole(): void
    {
        [$ecole, $code] = $this->classeAvecCode();

        for ($i = 0; $i < 200; $i++) {
            RateLimiter::hit(RejoindreParCodeService::cleDesEchecs((int) $ecole->getKey()), 86_400);
        }

        // Un refus métier, pas un seau : aucun `Retry-After`. Les `X-RateLimit-*`
        // sont là, posés par le middleware sur toute réponse qui a traversé le
        // seau du COMPTE — ils décrivent ce seau-là, pas le plafond de l'école
        // (mesuré, #924 ; `openapi.yaml` le dit).
        $this->asTenant($this->apprenant($ecole))->postJson(self::AUTHENTIFIEE, ['code' => $code])
            ->assertStatus(429)
            ->assertJsonPath('reason', 'institution_cap_reached')
            ->assertHeaderMissing('Retry-After')
            ->assertHeader('X-RateLimit-Limit');
    }

    public function test_porte_anonyme_la_borne_anti_inondation_de_l_adresse(): void
    {
        // #915 : le seau de route ne garde qu'une borne contre l'inondation,
        // 60 requêtes par minute et par adresse, sous la clé que
        // `ThrottleRequests:134` lui donne.
        [$ecole] = $this->classeAvecCode();

        for ($i = 0; $i < 60; $i++) {
            RateLimiter::hit($this->cleDeSeau('inscriptions', '127.0.0.1'), 60);
        }

        $this->unVrai429($this->postJson(self::ANONYME, $this->candidature('ZZZZZZ'), ['X-Institution' => (string) $ecole->slug]))
            ->assertJsonPath('reason', 'ip_quota_exceeded');
    }

    public function test_porte_anonyme_le_plafond_d_echecs_de_l_ecole(): void
    {
        // #915 : plus de plafond GLOBAL ; celui des ÉCHECS de l'école, partagé
        // avec la porte authentifiée (un même concept, un même motif).
        [$ecole] = $this->classeAvecCode();

        for ($i = 0; $i < 200; $i++) {
            RateLimiter::hit(RejoindreParCodeService::cleDesEchecs((int) $ecole->getKey()), 86_400);
        }

        $this->postJson(self::ANONYME, $this->candidature('ZZZZZZ'), ['X-Institution' => (string) $ecole->slug])
            ->assertStatus(429)
            ->assertJsonPath('reason', 'institution_cap_reached');
    }

    // ───────────────────────── harnais

    /**
     * La clé sous laquelle `ThrottleRequests:134` range une borne de seau nommé
     * — tant que `ThrottleRequests::$shouldHashKeys` vaut vrai, son défaut. Le
     * SEUL endroit de ce fichier qui connaît cette forme : pré-remplir un seau
     * évite des dizaines de requêtes, au prix de ce couplage-là.
     */
    private function cleDeSeau(string $nom, string $cleDeBorne): string
    {
        return md5($nom.$cleDeBorne);
    }

    /**
     * Un 429 produit par un seau : le statut tient, les en-têtes de débit que
     * `openapi.yaml` déclare survivent, et le message reste celui que le front
     * connaissait.
     *
     * @param  TestResponse<Response>  $reponse
     * @return TestResponse<Response>
     */
    private function unVrai429(TestResponse $reponse): TestResponse
    {
        return $reponse->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Trop de requêtes. Veuillez réessayer plus tard.');
    }

    /**
     * @return array<string, string>
     */
    private function candidature(string $code): array
    {
        return [
            'code' => $code,
            'nom' => 'Awa Kabore',
            'email' => 'awa@test.ci',
            'password' => 'mot-de-passe-choisi',
            'password_confirmation' => 'mot-de-passe-choisi',
        ];
    }
}
