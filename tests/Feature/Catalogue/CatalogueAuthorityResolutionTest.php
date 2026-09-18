<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Services\Catalogue\CatalogueAuthority;
use App\Services\Catalogue\KlassciCatalogueAuthority;
use App\Services\Catalogue\LocalCatalogueAuthority;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #848 — qui a le droit d'écrire le catalogue pédagogique.
 *
 * Même forme que la résolution du roster, et pour les mêmes raisons : le mode
 * est DÉCLARÉ, il se résout à la liaison — donc après `ResolveInstitution` —
 * et l'absence de tenant rend l'autorité la plus restrictive.
 *
 * Ce qui se joue ici est une frontière d'écriture : si un établissement branché
 * à KLASSCI pouvait créer une classe locale, la synchronisation suivante
 * n'aurait aucun moyen de savoir laquelle fait foi. C'est la cause racine de
 * #673, et elle a déjà été payée une fois.
 *
 * @see docs/adr/2026-09-18-848-01-catalogue-pedagogique-local.md
 */
final class CatalogueAuthorityResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_etablissement_klassci_n_ecrit_pas_son_catalogue(): void
    {
        $this->pourTenant(Institution::factory()->create([
            'mode' => InstitutionMode::Klassci,
        ]));

        $autorite = app(CatalogueAuthority::class);

        self::assertInstanceOf(KlassciCatalogueAuthority::class, $autorite);
        self::assertFalse($autorite->allowsLocalCatalogue());
    }

    public function test_un_etablissement_autonome_ecrit_son_catalogue(): void
    {
        $this->pourTenant(Institution::factory()->create([
            'mode' => InstitutionMode::Standalone,
        ]));

        $autorite = app(CatalogueAuthority::class);

        self::assertInstanceOf(LocalCatalogueAuthority::class, $autorite);
        self::assertTrue($autorite->allowsLocalCatalogue());
    }

    public function test_une_url_klassci_videe_ne_donne_pas_le_droit_d_ecrire(): void
    {
        // Le mode est une INTENTION. Le dériver de `klassci_api_url IS NULL`
        // ferait d'une faute de saisie une ouverture d'écriture, en silence.
        $this->pourTenant(Institution::factory()->create([
            'mode' => InstitutionMode::Klassci,
            'klassci_api_url' => null,
        ]));

        self::assertFalse(app(CatalogueAuthority::class)->allowsLocalCatalogue());
    }

    public function test_une_url_presente_ne_retire_pas_le_droit_a_un_autonome(): void
    {
        // Réciproque : la liaison réseau et l'intention sont orthogonales.
        $this->pourTenant(Institution::factory()->create([
            'mode' => InstitutionMode::Standalone,
            'klassci_api_url' => 'https://exemple.klassci.com/api/lms',
        ]));

        self::assertTrue(app(CatalogueAuthority::class)->allowsLocalCatalogue());
    }

    public function test_la_resolution_suit_le_tenant_et_ne_se_fige_pas(): void
    {
        // `bind()` et jamais `singleton()` : un job traverse plusieurs tenants
        // dans la même instance. Figé, le second hériterait du droit du premier
        // — une fuite entre établissements, pas un défaut de performance.
        $klassci = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
        $autonome = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);

        $this->pourTenant($klassci);
        self::assertInstanceOf(KlassciCatalogueAuthority::class, app(CatalogueAuthority::class));

        $this->pourTenant($autonome);
        self::assertInstanceOf(LocalCatalogueAuthority::class, app(CatalogueAuthority::class));
    }

    public function test_sans_tenant_resolu_aucune_ecriture_locale(): void
    {
        // Fail-secure : un job, une route publique, une commande de console.
        app(TenantManager::class)->reset();

        self::assertFalse(app(CatalogueAuthority::class)->allowsLocalCatalogue());
    }

    private function pourTenant(Institution $institution): void
    {
        app(TenantManager::class)->set($institution);
    }
}
