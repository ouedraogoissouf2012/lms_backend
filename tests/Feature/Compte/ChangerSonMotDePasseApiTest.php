<?php

declare(strict_types=1);

namespace Tests\Feature\Compte;

use App\Enums\InstitutionMode;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1667 — chacun change SON mot de passe : `PUT /api/auth/me/password`.
 *
 * - Corps : `current_password` (le mot de passe actuel, vérifié), `password` et `password_confirmation` (la politique
 *   en vigueur : 8 caractères au moins, confirmé), différent de l'actuel.
 * - École autonome (et supradmin de plateforme, compte local) : le mot de passe change, TOUS les autres jetons du compte
 *   sont révoqués (ses autres sessions se ferment), le jeton courant SURVIT ; l'acte est tracé à l'audit
 *   (`password_changed_by_self`), jamais le mot de passe.
 * - École KLASSCI : le mot de passe est celui de KLASSCI ; refus motivé 403 `password_managed_by_klassci`, rien n'est
 *   écrit (la connexion essaie le local d'abord : un mot de passe local ouvrirait une seconde porte, #1275).
 *
 * Sous VRAI jeton, entre deux requêtes le garde est oublié (`forgetGuards`) : sinon la seconde requête réutiliserait le
 * compte mémoïsé de la première, et un 200 ne prouverait rien.
 */
final class ChangerSonMotDePasseApiTest extends TestCase
{
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const ROUTE = '/api/auth/me/password';

    private const SPEC = '/auth/me/password';

    private const ACTUEL = 'Actuel-mdp-1667';

    private const NOUVEAU = 'Nouveau-mdp-1667';

    public function test_un_compte_d_ecole_autonome_change_son_mot_de_passe(): void
    {
        $compte = $this->compte(InstitutionMode::Standalone);

        $reponse = $this->changer($this->jeton($compte), self::ACTUEL, self::NOUVEAU)
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Mot de passe changé.']);

        $this->assertMatchesOpenApiResponse($reponse, self::SPEC, 'put');
        $frais = $compte->fresh();
        self::assertNotNull($frais);
        self::assertTrue(Hash::check(self::NOUVEAU, $frais->password));
        self::assertFalse(Hash::check(self::ACTUEL, $frais->password));
    }

    public function test_les_autres_jetons_sont_revoques_et_le_courant_survit(): void
    {
        $compte = $this->compte(InstitutionMode::Standalone);
        $courant = $this->jeton($compte);
        $autre = $this->jeton($compte);

        $this->changer($courant, self::ACTUEL, self::NOUVEAU)->assertOk();

        self::assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $compte->getKey())->count());
        $this->oublier();
        $this->withHeaders(['Authorization' => 'Bearer '.$courant])->getJson('/api/auth/me')->assertOk();
        $this->oublier();
        $this->withHeaders(['Authorization' => 'Bearer '.$autre])->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_un_mot_de_passe_actuel_faux_est_refuse_sans_rien_changer(): void
    {
        $compte = $this->compte(InstitutionMode::Standalone);
        $this->jeton($compte);

        $reponse = $this->changer($this->jeton($compte), 'pas-le-bon-mdp', self::NOUVEAU)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertMatchesOpenApiResponse($reponse, self::SPEC, 'put');
        $this->rienNAChange($compte, 2);
    }

    public function test_le_nouveau_mot_de_passe_differe_de_l_actuel(): void
    {
        $compte = $this->compte(InstitutionMode::Standalone);

        $this->changer($this->jeton($compte), self::ACTUEL, self::ACTUEL)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_un_mot_de_passe_trop_court_ou_non_confirme_est_refuse(): void
    {
        $compte = $this->compte(InstitutionMode::Standalone);

        $this->changer($this->jeton($compte), self::ACTUEL, 'court')->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->oublier();
        $this->withHeaders(['Authorization' => 'Bearer '.$this->jeton($compte)])
            ->putJson(self::ROUTE, ['current_password' => self::ACTUEL, 'password' => self::NOUVEAU, 'password_confirmation' => 'autre-chose-1667'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
        $this->rienNAChange($compte, 2);
    }

    public function test_un_compte_d_ecole_klassci_est_renvoye_a_klassci(): void
    {
        $compte = $this->compte(InstitutionMode::Klassci);

        $reponse = $this->changer($this->jeton($compte), self::ACTUEL, self::NOUVEAU)
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Le mot de passe se change dans KLASSCI.', 'reason' => 'password_managed_by_klassci']);

        $this->assertMatchesOpenApiResponse($reponse, self::SPEC, 'put');
        $this->rienNAChange($compte, 1);
    }

    public function test_le_supradmin_de_plateforme_change_le_sien(): void
    {
        // Sans établissement : son compte est LOCAL ; il ne doit pas être renvoyé à KLASSCI.
        $supradmin = User::factory()->create(['role' => 'supradmin', 'password' => Hash::make(self::ACTUEL)]);

        $this->changer($this->jeton($supradmin), self::ACTUEL, self::NOUVEAU)->assertOk();

        $frais = $supradmin->fresh();
        self::assertNotNull($frais);
        self::assertTrue(Hash::check(self::NOUVEAU, $frais->password));
    }

    public function test_l_acte_est_trace_a_l_audit_sans_le_mot_de_passe(): void
    {
        $compte = $this->compte(InstitutionMode::Standalone);

        $this->changer($this->jeton($compte), self::ACTUEL, self::NOUVEAU)->assertOk();

        $trace = AuditLog::query()->withoutGlobalScopes()->where('action', 'password_changed_by_self')->sole();
        $brut = (string) json_encode($trace->getAttributes(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::NOUVEAU, $brut);
        self::assertStringNotContainsString(self::ACTUEL, $brut);
    }

    public function test_sans_jeton_rien_n_est_change(): void
    {
        $this->putJson(self::ROUTE, ['current_password' => self::ACTUEL, 'password' => self::NOUVEAU, 'password_confirmation' => self::NOUVEAU])
            ->assertUnauthorized();
    }

    // ───────────────────────────────── harnais

    private function compte(InstitutionMode $mode, string $role = 'enseignant'): User
    {
        return User::factory()->create([
            'institution_id' => Institution::factory()->create(['mode' => $mode])->getKey(),
            'role' => $role,
            'password' => Hash::make(self::ACTUEL),
        ]);
    }

    private function jeton(User $compte): string
    {
        return $compte->createToken('mon-mot-de-passe-1667')->plainTextToken;
    }

    private function changer(string $jeton, string $actuel, string $nouveau): TestResponse
    {
        $this->oublier();

        return $this->withHeaders(['Authorization' => 'Bearer '.$jeton])
            ->putJson(self::ROUTE, ['current_password' => $actuel, 'password' => $nouveau, 'password_confirmation' => $nouveau]);
    }

    /** Le garde mémoïse le compte d'une requête à l'autre : on l'oublie, et les en-têtes avec. */
    private function oublier(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
    }

    private function rienNAChange(User $compte, int $jetons): void
    {
        $frais = $compte->fresh();
        self::assertNotNull($frais);
        self::assertTrue(Hash::check(self::ACTUEL, $frais->password), 'Le mot de passe a changé malgré le refus.');
        self::assertSame($jetons, DB::table('personal_access_tokens')->where('tokenable_id', $compte->getKey())->count(), 'Un refus a révoqué des jetons.');
    }
}
