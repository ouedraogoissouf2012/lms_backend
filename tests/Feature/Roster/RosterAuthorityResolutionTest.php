<?php

declare(strict_types=1);

namespace Tests\Feature\Roster;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Services\Roster\KlassciRosterAuthority;
use App\Services\Roster\LocalRosterAuthority;
use App\Services\Roster\RosterAuthority;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #805 — le mode se résout en UN seul point, et il est DÉCLARÉ.
 *
 * Deux décisions se vérifient ici. D'abord que le mode est une donnée d'intention
 * et non un effet de bord de configuration : une URL KLASSCI vidée par erreur ne
 * doit pas faire basculer un établissement de production. Ensuite que la
 * résolution se fait à la liaison, donc APRÈS `ResolveInstitution` — d'où un
 * `bind()` et jamais un `singleton()`, qui figerait le premier tenant résolu.
 */
final class RosterAuthorityResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_par_defaut_une_institution_est_klassci(): void
    {
        // Aucune reprise de données : toute ligne existante garde le
        // comportement d'aujourd'hui.
        $institution = Institution::factory()->create();

        self::assertSame(InstitutionMode::Klassci, $institution->mode);
    }

    public function test_un_etablissement_klassci_n_ecrit_pas_son_roster(): void
    {
        $this->pourTenant(Institution::factory()->create([
            'mode' => InstitutionMode::Klassci,
        ]));

        $autorite = app(RosterAuthority::class);

        self::assertInstanceOf(KlassciRosterAuthority::class, $autorite);
        self::assertFalse($autorite->allowsLocalEnrolment());
    }

    public function test_un_etablissement_autonome_ecrit_son_roster(): void
    {
        $this->pourTenant(Institution::factory()->create([
            'mode' => InstitutionMode::Standalone,
        ]));

        self::assertTrue(app(RosterAuthority::class)->allowsLocalEnrolment());
    }

    public function test_une_url_klassci_videe_ne_rend_pas_un_tenant_autonome(): void
    {
        // Le défaut que le plan interdit nommément : dériver le mode de
        // `klassci_api_url IS NULL` ferait d'une faute de saisie un changement
        // de mode en production, en silence.
        $this->pourTenant(Institution::factory()->create([
            'mode' => InstitutionMode::Klassci,
            'klassci_api_url' => null,
        ]));

        self::assertFalse(app(RosterAuthority::class)->allowsLocalEnrolment());
    }

    public function test_une_url_presente_n_interdit_pas_un_tenant_autonome(): void
    {
        // Réciproque : le mode déclaré fait foi, pas la configuration réseau.
        $this->pourTenant(Institution::factory()->create([
            'mode' => InstitutionMode::Standalone,
            'klassci_api_url' => 'https://exemple.klassci.com/api/lms',
        ]));

        self::assertTrue(app(RosterAuthority::class)->allowsLocalEnrolment());
    }

    public function test_la_resolution_suit_le_tenant_courant_et_ne_se_fige_pas(): void
    {
        // Un job traverse plusieurs tenants dans la même instance. Avec un
        // `singleton`, le second recevrait l'autorité du premier — soit une
        // fuite entre établissements.
        $klassci = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
        $autonome = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);

        $this->pourTenant($klassci);
        self::assertInstanceOf(KlassciRosterAuthority::class, app(RosterAuthority::class));

        $this->pourTenant($autonome);
        self::assertInstanceOf(LocalRosterAuthority::class, app(RosterAuthority::class));
    }

    public function test_sans_tenant_resolu_l_autorite_est_la_plus_restrictive(): void
    {
        // Fail-secure : hors contexte d'établissement, on n'autorise rien.
        app(TenantManager::class)->reset();

        self::assertFalse(app(RosterAuthority::class)->allowsLocalEnrolment());
    }

    private function pourTenant(Institution $institution): void
    {
        app(TenantManager::class)->set($institution);
    }
}
