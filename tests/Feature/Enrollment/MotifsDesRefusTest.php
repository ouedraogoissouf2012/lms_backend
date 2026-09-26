<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\Enrollment\ClasseEnrolmentCodeService;
use App\Services\Enrollment\RejoindreParCodeService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #906 — chaque refus d'inscription porte un MOTIF que le front peut lire.
 *
 * ## Le manque
 *
 * Le front n'affiche jamais le message du serveur (hors 422) : il traduit un
 * STATUT par son propre catalogue. Or un même statut portait ici plusieurs sens —
 * « connectez-vous » et « votre inscription n'est pas active » sont deux 409 ;
 * « attendez une minute » et « votre école est bloquée jusqu'à demain », deux
 * 429. Le 409 finissait en « erreur inattendue », et l'apprenant chez le support.
 *
 * ## Ce que ce fichier garde
 *
 * Qu'à chaque sens correspond UN motif stable, dans le CORPS — jamais dans un
 * en-tête : le front de production est cross-origin, et `exposed_headers: []`
 * rend tout en-tête non standard invisible au navigateur.
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
    use RefreshDatabase;

    private const ANONYME = '/api/inscriptions';

    private const AUTHENTIFIEE = '/api/me/inscriptions';

    // Aucun nettoyage des seaux ici : `Tests\TestCase::setUp()` vide le store
    // Redis avant chaque test (#374), et `RefreshDatabase` annule la table
    // `cache` en mode database. Chaque test part de compteurs à zéro.

    // ───────────────────────── les deux 409, un par porte

    public function test_porte_anonyme_un_compte_existant_dit_connectez_vous(): void
    {
        [$ecole, $code] = $this->classeAvecCode();
        User::factory()->create(['institution_id' => $ecole->getKey(), 'email' => 'awa@test.ci']);

        $this->postJson(self::ANONYME, $this->candidature($code), ['X-Institution' => (string) $ecole->slug])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'account_exists');
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

        $this->asTenant($sansEcole)->postJson(self::AUTHENTIFIEE, ['code' => $code])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'no_institution');
    }

    public function test_les_deux_409_ne_portent_jamais_le_meme_motif(): void
    {
        // La prémisse de #906, sous forme exécutable : deux sens, deux motifs.
        [$ecole, $code, $classe] = $this->classeAvecCode();
        User::factory()->create(['institution_id' => $ecole->getKey(), 'email' => 'awa@test.ci']);
        $anonyme = $this->postJson(self::ANONYME, $this->candidature($code), ['X-Institution' => (string) $ecole->slug]);

        $apprenant = $this->apprenant($ecole, 'autre@test.ci');
        $classe->etudiants()->attach($apprenant->id, ['statut' => 'abandonne']);
        $authentifiee = $this->asTenant($apprenant)->postJson(self::AUTHENTIFIEE, ['code' => $code]);

        self::assertNotNull($anonyme->json('reason'));
        self::assertNotSame($anonyme->json('reason'), $authentifiee->json('reason'));
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

    public function test_porte_authentifiee_le_quota_journalier_du_compte_porte_le_meme_motif(): void
    {
        // Le seau du compte a DEUX bornes, chacune avec son propre rendu : la
        // journalière doit dire la même chose que celle de la minute. Remplie
        // sous la clé que `ThrottleRequests:134` lui donne.
        [$ecole] = $this->classeAvecCode();
        $apprenant = $this->apprenant($ecole);

        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit($this->cleDeSeau('rejoindre-classe', 'rejoindre-jour|user:'.$apprenant->id), 86_400);
        }

        $this->unVrai429($this->asTenant($apprenant)->postJson(self::AUTHENTIFIEE, ['code' => 'ZZZZZZ']))
            ->assertJsonPath('reason', 'account_quota_exceeded');
    }

    public function test_porte_authentifiee_le_plafond_de_l_ecole(): void
    {
        [$ecole, $code] = $this->classeAvecCode();

        for ($i = 0; $i < 200; $i++) {
            RateLimiter::hit(RejoindreParCodeService::cleDesEchecs((int) $ecole->getKey()), 86_400);
        }

        $this->asTenant($this->apprenant($ecole))->postJson(self::AUTHENTIFIEE, ['code' => $code])
            ->assertStatus(429)
            ->assertJsonPath('reason', 'institution_cap_reached');
    }

    public function test_porte_anonyme_le_quota_de_l_adresse(): void
    {
        [$ecole] = $this->classeAvecCode();
        $entete = ['X-Institution' => (string) $ecole->slug];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::ANONYME, $this->candidature('ZZZZZZ'), $entete);
        }

        $this->unVrai429($this->postJson(self::ANONYME, $this->candidature('ZZZZZZ'), $entete))
            ->assertJsonPath('reason', 'ip_quota_exceeded');
    }

    public function test_porte_anonyme_le_plafond_global_du_jour(): void
    {
        // Éprouver 100 inscriptions par le trafic demanderait 100 requêtes et
        // vingt adresses : on remplit le compteur global, sous la clé que
        // `ThrottleRequests:134` lui donne.
        [$ecole] = $this->classeAvecCode();

        for ($i = 0; $i < 100; $i++) {
            RateLimiter::hit($this->cleDeSeau('inscriptions', 'inscriptions-global'), 86_400);
        }

        $this->unVrai429($this->postJson(self::ANONYME, $this->candidature('ZZZZZZ'), ['X-Institution' => (string) $ecole->slug]))
            ->assertJsonPath('reason', 'global_cap_reached');
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
     * Un 429 produit par un seau : le statut tient, `Retry-After` survit, et le
     * message reste celui que le front connaissait.
     *
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $reponse
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function unVrai429(TestResponse $reponse): TestResponse
    {
        return $reponse->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Trop de requêtes. Veuillez réessayer plus tard.');
    }

    private function apprenant(Institution $ecole, string $email = 'awa@test.ci'): User
    {
        return User::factory()->create([
            'institution_id' => $ecole->getKey(),
            'email' => $email,
            'role' => 'etudiant',
        ]);
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

    /**
     * @return array{0: Institution, 1: string, 2: Classe}
     */
    private function classeAvecCode(): array
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);
        app(TenantManager::class)->set($ecole);

        $classe = Classe::factory()->create([
            'institution_id' => $ecole->getKey(),
            'klassci_id' => null,
        ]);

        $code = app(ClasseEnrolmentCodeService::class)->generer($classe->getKey());

        // Le tenant est ensuite posé par le jeton ou l'en-tête, comme en production.
        app(TenantManager::class)->reset();

        return [$ecole, $code, $classe];
    }
}
