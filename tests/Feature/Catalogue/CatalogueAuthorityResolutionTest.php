<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Services\Catalogue\CatalogueAuthority;
use App\Services\Catalogue\KlassciCatalogueAuthority;
use App\Services\Catalogue\LocalCatalogueAuthority;
use App\Services\Roster\RosterAuthorityFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #848 — qui a le droit d'écrire le catalogue pédagogique.
 *
 * Le mode est DÉCLARÉ, et l'absence d'établissement rend l'autorité la plus
 * restrictive. Ce qui se joue ici est une frontière d'écriture : si un établissement
 * branché à KLASSCI pouvait créer une classe locale, la synchronisation suivante
 * n'aurait aucun moyen de savoir laquelle fait foi. C'est la cause racine de #673,
 * et elle a déjà été payée une fois.
 *
 * #1142 — le droit se lit par `RosterAuthorityFactory::catalogueForInstitution()`, au
 * moment de l'appel (`PorteDuCatalogueLocal`, #1128). La liaison de conteneur
 * `CatalogueAuthority` et `catalogueForCurrentTenant()` n'avaient plus aucun
 * consommateur : elles sont retirées, et ce test, qui les résolvait, est reciblé sur
 * la fabrique pour garder la couverture de la règle du mode.
 *
 * @see docs/adr/2026-09-18-848-01-catalogue-pedagogique-local.md
 */
final class CatalogueAuthorityResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_etablissement_klassci_n_ecrit_pas_son_catalogue(): void
    {
        $autorite = $this->autoritePour(Institution::factory()->create(['mode' => InstitutionMode::Klassci]));

        self::assertInstanceOf(KlassciCatalogueAuthority::class, $autorite);
        self::assertFalse($autorite->allowsLocalCatalogue());
    }

    public function test_un_etablissement_autonome_ecrit_son_catalogue(): void
    {
        $autorite = $this->autoritePour(Institution::factory()->create(['mode' => InstitutionMode::Standalone]));

        self::assertInstanceOf(LocalCatalogueAuthority::class, $autorite);
        self::assertTrue($autorite->allowsLocalCatalogue());
    }

    public function test_une_url_klassci_videe_ne_donne_pas_le_droit_d_ecrire(): void
    {
        // Le mode est une INTENTION. Le dériver de `klassci_api_url IS NULL`
        // ferait d'une faute de saisie une ouverture d'écriture, en silence.
        $autorite = $this->autoritePour(Institution::factory()->create([
            'mode' => InstitutionMode::Klassci,
            'klassci_api_url' => null,
        ]));

        self::assertFalse($autorite->allowsLocalCatalogue());
    }

    public function test_une_url_presente_ne_retire_pas_le_droit_a_un_autonome(): void
    {
        // Réciproque : la liaison réseau et l'intention sont orthogonales.
        $autorite = $this->autoritePour(Institution::factory()->create([
            'mode' => InstitutionMode::Standalone,
            'klassci_api_url' => 'https://exemple.klassci.com/api/lms',
        ]));

        self::assertTrue($autorite->allowsLocalCatalogue());
    }

    public function test_la_resolution_suit_l_etablissement_et_ne_se_fige_pas(): void
    {
        // Un job traverse plusieurs établissements dans la même instance. Figé, le
        // second hériterait du droit du premier : une fuite entre établissements.
        $fabrique = app(RosterAuthorityFactory::class);

        self::assertInstanceOf(KlassciCatalogueAuthority::class, $fabrique->catalogueForInstitution(Institution::factory()->create(['mode' => InstitutionMode::Klassci])));
        self::assertInstanceOf(LocalCatalogueAuthority::class, $fabrique->catalogueForInstitution(Institution::factory()->create(['mode' => InstitutionMode::Standalone])));
    }

    public function test_sans_etablissement_aucune_ecriture_locale(): void
    {
        // Fail-secure : un job, une route publique, une commande de console.
        self::assertFalse($this->autoritePour(null)->allowsLocalCatalogue());
    }

    /** #1142 — plus aucune liaison de conteneur : le droit ne se lit que par la fabrique. */
    public function test_aucune_liaison_de_conteneur_pour_l_autorite_du_catalogue(): void
    {
        self::assertFalse(app()->bound(CatalogueAuthority::class));
    }

    /** #1142 — plus de résolution par le tenant courant : le droit se lit pour un établissement donné. */
    public function test_la_fabrique_ne_resout_plus_par_le_tenant_courant(): void
    {
        self::assertFalse(method_exists(RosterAuthorityFactory::class, 'catalogueForCurrentTenant'));
    }

    private function autoritePour(?Institution $institution): CatalogueAuthority
    {
        return app(RosterAuthorityFactory::class)->catalogueForInstitution($institution);
    }
}
