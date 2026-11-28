<?php

declare(strict_types=1);

namespace Tests\Feature\Compte;

use App\Enums\InstitutionMode;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1667 — chacun modifie SON profil : `PATCH /api/auth/me`, le nom affiché et le téléphone.
 *
 * - Le COURRIEL est hors périmètre (ARRÊT 1 validé) : c'est l'identifiant de connexion ; le changer exige une
 *   confirmation par lien (autre issue). Le corps qui le porte est REFUSÉ (422 `errors.email`), jamais ignoré en
 *   silence. La photo l'est aussi (aucune colonne, autre issue).
 * - École autonome (et supradmin de plateforme) : le profil local est modifié, l'acte tracé (`profile_updated_by_self`).
 * - École KLASSCI : le profil vient de KLASSCI, et la synchronisation écraserait la saisie ; refus motivé 403
 *   `profile_managed_by_klassci`, rien n'est écrit.
 * Sous VRAI jeton ; chaque corps suit `docs/openapi.yaml`.
 */
final class ModifierSonProfilApiTest extends TestCase
{
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const ROUTE = '/api/auth/me';

    private const SPEC = '/auth/me';

    public function test_un_compte_d_ecole_autonome_modifie_son_nom_et_son_telephone(): void
    {
        $compte = $this->compte(InstitutionMode::Standalone);

        $reponse = $this->modifier($compte, ['name' => 'Awa Nouvelle', 'phone' => '+226 70 00 00 00'])
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Profil mis à jour.', 'data' => ['name' => 'Awa Nouvelle', 'phone' => '+226 70 00 00 00']]);

        $this->assertMatchesOpenApiResponse($reponse, self::SPEC, 'patch');
        $frais = $compte->fresh();
        self::assertNotNull($frais);
        self::assertSame(['Awa Nouvelle', '+226 70 00 00 00'], [$frais->name, $frais->phone]);
    }

    public function test_un_seul_champ_ne_touche_pas_l_autre(): void
    {
        $compte = $this->compte(InstitutionMode::Standalone, ['name' => 'Awa Fictive', 'phone' => '+226 71 11 11 11']);

        $this->modifier($compte, ['phone' => null])->assertOk()->assertJsonPath('data', ['name' => 'Awa Fictive', 'phone' => null]);
    }

    public function test_le_courriel_n_est_pas_modifiable_par_cette_route(): void
    {
        $compte = $this->compte(InstitutionMode::Standalone, ['name' => 'Awa Fictive']);
        $courriel = $compte->email;

        $reponse = $this->modifier($compte, ['name' => 'Autre', 'email' => 'autre@ecole.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertMatchesOpenApiResponse($reponse, self::SPEC, 'patch');
        $frais = $compte->fresh();
        self::assertNotNull($frais);
        self::assertSame([$courriel, 'Awa Fictive'], [$frais->email, $frais->name], 'Un refus a écrit.');
    }

    public function test_un_nom_vide_ou_trop_long_est_refuse(): void
    {
        $compte = $this->compte(InstitutionMode::Standalone);

        $this->modifier($compte, ['name' => '  '])->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->modifier($compte, ['name' => str_repeat('a', 256)])->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->modifier($compte, ['phone' => str_repeat('1', 41)])->assertStatus(422)->assertJsonValidationErrors(['phone']);
    }

    public function test_un_compte_d_ecole_klassci_est_renvoye_a_klassci(): void
    {
        $compte = $this->compte(InstitutionMode::Klassci, ['name' => 'Nom venu de KLASSCI']);

        $reponse = $this->modifier($compte, ['name' => 'Saisie locale'])
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Le profil se modifie dans KLASSCI.', 'reason' => 'profile_managed_by_klassci']);

        $this->assertMatchesOpenApiResponse($reponse, self::SPEC, 'patch');
        $frais = $compte->fresh();
        self::assertNotNull($frais);
        self::assertSame('Nom venu de KLASSCI', $frais->name);
    }

    public function test_le_supradmin_de_plateforme_modifie_le_sien(): void
    {
        $supradmin = User::factory()->create(['role' => 'supradmin', 'name' => 'Plateforme']);

        $this->modifier($supradmin, ['name' => 'Plateforme renommée'])->assertOk();

        $frais = $supradmin->fresh();
        self::assertNotNull($frais);
        self::assertSame('Plateforme renommée', $frais->name);
    }

    public function test_l_acte_est_trace_a_l_audit(): void
    {
        $compte = $this->compte(InstitutionMode::Standalone);

        $this->modifier($compte, ['name' => 'Awa Nouvelle'])->assertOk();

        self::assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'profile_updated_by_self')->count());
    }

    // ───────────────────────────────── harnais

    /** @param array<string, mixed> $attributs */
    private function compte(InstitutionMode $mode, array $attributs = []): User
    {
        return User::factory()->create([
            'institution_id' => Institution::factory()->create(['mode' => $mode])->getKey(),
            'role' => 'enseignant',
            ...$attributs,
        ]);
    }

    /** @param array<string, mixed> $corps */
    private function modifier(User $compte, array $corps): TestResponse
    {
        // Le garde mémoïse le compte d'une requête à l'autre : on l'oublie, et les en-têtes avec.
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$compte->createToken('mon-profil-1667')->plainTextToken])
            ->patchJson(self::ROUTE, $corps);
    }
}
