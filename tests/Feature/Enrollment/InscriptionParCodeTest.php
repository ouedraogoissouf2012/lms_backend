<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\InstitutionMode;
use App\Enums\Role;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\Enrollment\ClasseEnrolmentCodeService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * #846 (lot C) — l'apprenant s'inscrit lui-même avec un code.
 *
 * ## Ce que ce fichier garde AVANT tout
 *
 * **Qu'un compte existant n'est jamais touché.** C'est la faille de #812,
 * durcie en #823 : sur un endpoint anonyme, une adresse n'est pas une preuve de
 * propriété. Poser un mot de passe sur un compte existant serait une prise de
 * contrôle — il suffirait de connaître une adresse et un code de classe.
 *
 * Mon auto-critique avait alors documenté l'écrasement comme correct, et c'est
 * une relecture humaine qui l'a rattrapé. Les tests de cette classe sont écrits
 * en conséquence : ils vérifient le mot de passe **inchangé**, pas seulement
 * l'absence de création.
 *
 * @see docs/adr/2026-09-15-803-03-trois-portes-un-service.md
 */
final class InscriptionParCodeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/inscriptions';

    // ───────────────────────── le parcours nominal

    public function test_un_candidat_rejoint_la_classe_et_choisit_son_mot_de_passe(): void
    {
        [$ecole, $code] = $this->classeAvecCode();

        $this->postJson(self::URL, $this->charge($code), $this->entete($ecole))
            ->assertStatus(201);

        $inscrit = User::query()->withoutGlobalScopes()->where('email', 'awa@test.ci')->firstOrFail();

        self::assertSame(Role::Etudiant->value, $inscrit->role);
        self::assertSame($ecole->getKey(), $inscrit->institution_id);
        self::assertTrue(
            Hash::check('mot-de-passe-choisi', (string) $inscrit->password),
            'Le mot de passe choisi doit ouvrir le compte.'
        );
        self::assertSame(1, DB::table('classe_etudiant')->count());
    }

    public function test_le_code_est_insensible_a_la_casse_et_aux_espaces(): void
    {
        // Il est dicté au téléphone et recopié depuis un message.
        [$ecole, $code] = $this->classeAvecCode();

        $this->postJson(self::URL, $this->charge('  '.strtolower($code).' '), $this->entete($ecole))
            ->assertStatus(201);
    }

    public function test_un_numero_de_telephone_suffit(): void
    {
        // Le produit vit là où le téléphone est plus sûr que le courriel.
        [$ecole, $code] = $this->classeAvecCode();

        $charge = $this->charge($code);
        unset($charge['email']);
        $charge['telephone'] = '+22507000000';

        $this->postJson(self::URL, $charge, $this->entete($ecole))->assertStatus(201);
    }

    // ───────────────────────── LA faille à ne pas refaire

    public function test_un_compte_EXISTANT_n_est_JAMAIS_touche(): void
    {
        // #812 : poser un mot de passe sur un compte existant serait une prise
        // de contrôle. On vérifie l'ancien mot de passe INCHANGÉ, pas seulement
        // l'absence de création.
        [$ecole, $code] = $this->classeAvecCode();
        $victime = User::factory()->create([
            'institution_id' => $ecole->getKey(),
            'email' => 'awa@test.ci',
            'role' => 'enseignant',
            'password' => Hash::make('son-vrai-secret'),
        ]);

        $this->postJson(self::URL, $this->charge($code), $this->entete($ecole))
            ->assertStatus(409);

        $relu = $victime->fresh();

        self::assertTrue(
            Hash::check('son-vrai-secret', (string) $relu?->password),
            'PRISE DE CONTRÔLE : le mot de passe du compte existant a été remplacé.'
        );
        self::assertFalse(
            Hash::check('mot-de-passe-choisi', (string) $relu?->password),
            'PRISE DE CONTRÔLE : le mot de passe soumis ouvre le compte de la victime.'
        );
        self::assertSame('enseignant', $relu?->role, 'Le rôle du compte existant a été modifié.');
        self::assertSame(0, DB::table('classe_etudiant')->count(), 'La victime a été inscrite sans son consentement.');
    }

    public function test_un_numero_deja_pris_est_refuse_de_la_meme_facon(): void
    {
        [$ecole, $code] = $this->classeAvecCode();
        User::factory()->create([
            'institution_id' => $ecole->getKey(),
            'phone' => '+22507000000',
            'email' => null,
        ]);

        $charge = $this->charge($code);
        unset($charge['email']);
        $charge['telephone'] = '+22507000000';

        $this->postJson(self::URL, $charge, $this->entete($ecole))->assertStatus(409);
    }

    // ───────────────────────── les autres refus

    public function test_un_code_inconnu_et_un_code_RETIRE_rendent_le_meme_refus(): void
    {
        // Les distinguer dirait qu'un code a existé pour cette valeur — donc
        // qu'une classe l'attend. Même raisonnement qu'ADR-803-02.
        [$ecole, $code, $classe] = $this->classeAvecCode();

        $inconnu = $this->postJson(self::URL, $this->charge('ZZZZZZ'), $this->entete($ecole))
            ->assertStatus(404);

        app(TenantManager::class)->set($ecole);
        app(ClasseEnrolmentCodeService::class)->revoquer($classe->getKey());

        $retire = $this->postJson(self::URL, $this->charge($code), $this->entete($ecole))
            ->assertStatus(404);

        self::assertSame($inconnu->json('message'), $retire->json('message'));
        self::assertSame(0, DB::table('classe_etudiant')->count());
    }

    public function test_le_code_d_une_AUTRE_ecole_n_ouvre_rien(): void
    {
        // `code_inscription` n'est unique que par établissement : sans la borne,
        // un candidat atterrirait dans la mauvaise école.
        [, $code] = $this->classeAvecCode();
        $voisine = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);

        $this->postJson(self::URL, $this->charge($code), $this->entete($voisine))
            ->assertStatus(404);

        self::assertSame(0, DB::table('classe_etudiant')->count());
    }

    public function test_sans_en_tete_d_etablissement_la_route_refuse(): void
    {
        [, $code] = $this->classeAvecCode();

        $this->postJson(self::URL, $this->charge($code))->assertStatus(400);
    }

    public function test_un_mot_de_passe_non_confirme_est_refuse_avant_toute_ecriture(): void
    {
        [$ecole, $code] = $this->classeAvecCode();

        $charge = $this->charge($code);
        $charge['password_confirmation'] = 'autre-chose';

        $this->postJson(self::URL, $charge, $this->entete($ecole))->assertStatus(422);

        self::assertSame(0, User::query()->withoutGlobalScopes()->where('email', 'awa@test.ci')->count());
    }

    public function test_sans_adresse_ni_telephone_la_demande_est_refusee(): void
    {
        [$ecole, $code] = $this->classeAvecCode();

        $charge = $this->charge($code);
        unset($charge['email']);

        $this->postJson(self::URL, $charge, $this->entete($ecole))->assertStatus(422);
    }

    // ───────────────────────── harnais

    /**
     * @return array<string, string>
     */
    private function entete(Institution $ecole): array
    {
        return ['X-Institution' => (string) $ecole->slug];
    }

    /**
     * @return array<string, string>
     */
    private function charge(string $code): array
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

        // Le tenant est ensuite posé par l'en-tête, comme en production.
        app(TenantManager::class)->reset();

        return [$ecole, $code, $classe];
    }
}
