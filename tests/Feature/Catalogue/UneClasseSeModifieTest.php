<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1652 (PR A) — une classe LOCALE se modifie : libellé, code, description.
 *
 * Seuls les champs envoyés changent ; un code vide efface le code. Le code est unique dans l'école
 * (unique `(institution_id, code)` déjà en base) : 422 `class_code_already_used`, comme à la
 * création (#1521). Une classe miroir de KLASSCI, comme une école KLASSCI : 403 ; une classe d'une
 * autre école : 404.
 *
 * Vrai jeton (`asTenant`), les deux volets.
 */
final class UneClasseSeModifieTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    public function test_une_classe_locale_se_modifie(): void
    {
        [$gestion, $ecole] = $this->gestion();
        $classe = $this->classe($ecole, '6A');

        $this->asTenant($gestion)->patchJson("/api/classes/{$classe->id}", [
            'libelle' => '6e A renommée', 'code' => '6A-BIS', 'description' => 'Section bilingue',
        ])->assertOk()
            ->assertJsonPath('data.libelle', '6e A renommée')
            ->assertJsonPath('data.code', '6A-BIS')
            ->assertJsonPath('data.description', 'Section bilingue');
    }

    public function test_un_champ_absent_garde_sa_valeur_et_un_code_vide_l_efface(): void
    {
        [$gestion, $ecole] = $this->gestion();
        $classe = $this->classe($ecole, '6A');

        $this->asTenant($gestion)->patchJson("/api/classes/{$classe->id}", ['description' => 'Nouvelle'])->assertOk()
            ->assertJsonPath('data.code', '6A')
            ->assertJsonPath('data.libelle', $classe->libelle);
        $this->asTenant($gestion)->patchJson("/api/classes/{$classe->id}", ['code' => '  '])->assertOk()
            ->assertJsonPath('data.code', null);
    }

    public function test_un_code_deja_pris_dans_l_ecole_est_refuse(): void
    {
        [$gestion, $ecole] = $this->gestion();
        $this->classe($ecole, '6A');
        $autre = $this->classe($ecole, '6B');

        $this->asTenant($gestion)->patchJson("/api/classes/{$autre->id}", ['code' => '6A'])
            ->assertStatus(422)->assertJsonValidationErrors(['code'])
            ->assertJsonPath('reason', 'class_code_already_used');
        self::assertSame('6B', $autre->fresh()?->code);
    }

    public function test_garder_son_propre_code_n_est_pas_un_doublon(): void
    {
        [$gestion, $ecole] = $this->gestion();
        $classe = $this->classe($ecole, '6A');

        $this->asTenant($gestion)->patchJson("/api/classes/{$classe->id}", ['code' => '6A', 'libelle' => 'Renommée'])->assertOk();
    }

    public function test_une_classe_miroir_ne_se_modifie_pas_ici(): void
    {
        [$gestion, $ecole] = $this->gestion();
        $miroir = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => 4242]);

        $this->asTenant($gestion)->patchJson("/api/classes/{$miroir->id}", ['libelle' => 'Renommée'])
            ->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
    }

    public function test_une_classe_d_une_autre_ecole_est_introuvable(): void
    {
        [$gestion] = $this->gestion();
        $ailleurs = $this->classe(Institution::factory()->create(['mode' => InstitutionMode::Standalone]), '6Z');

        $this->asTenant($gestion)->patchJson("/api/classes/{$ailleurs->id}", ['libelle' => 'Intrusion'])->assertNotFound();
        self::assertNotSame('Intrusion', Classe::query()->withoutGlobalScopes()->find($ailleurs->id)?->libelle);
    }

    public function test_une_ecole_klassci_est_refusee(): void
    {
        [$gestion, $ecole] = $this->gestion(InstitutionMode::Klassci);
        $classe = $this->classe($ecole, '6A');

        $this->asTenant($gestion)->patchJson("/api/classes/{$classe->id}", ['libelle' => 'Renommée'])
            ->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
    }

    public function test_un_libelle_vide_est_refuse(): void
    {
        [$gestion, $ecole] = $this->gestion();
        $classe = $this->classe($ecole, '6A');

        $this->asTenant($gestion)->patchJson("/api/classes/{$classe->id}", ['libelle' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['libelle']);
    }

    /** @return array{0: User, 1: Institution} */
    private function gestion(InstitutionMode $mode = InstitutionMode::Standalone): array
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);

        return [User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin', 'klassci_token' => null]), $ecole];
    }

    private function classe(Institution $ecole, string $code): Classe
    {
        return Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null, 'code' => $code]);
    }
}
