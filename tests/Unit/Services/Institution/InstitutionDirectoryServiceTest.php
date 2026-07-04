<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Institution;

use App\Models\Institution;
use App\Services\Institution\InstitutionDirectoryService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unitaires du service d'annuaire public des institutions (issue #375).
 *
 * Le contrat HTTP complet (enveloppe, 400, ordre JSON) est figé par
 * {@see \Tests\Feature\Api\PublicCoreRoutesContractTest} ; ici on teste la
 * logique du service isolément (§1.3 : happy path + edge case).
 */
final class InstitutionDirectoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(?TenantManager $tenantManager = null): InstitutionDirectoryService
    {
        return new InstitutionDirectoryService($tenantManager ?? new TenantManager());
    }

    public function test_list_active_filtre_les_inactives_trie_par_nom_et_ne_selectionne_que_les_champs_publics(): void
    {
        Institution::factory()->create(['name' => 'Zeta', 'slug' => 'zeta', 'is_active' => true]);
        Institution::factory()->create(['name' => 'Alpha', 'slug' => 'alpha', 'is_active' => true]);
        Institution::factory()->create(['name' => 'Morte', 'slug' => 'morte', 'is_active' => false]);

        $result = $this->makeService()->listActive();

        $this->assertSame(['alpha', 'zeta'], $result->pluck('slug')->all());
        // Seuls les 4 champs publics sont hydratés — aucun risque de fuite
        // (klassci_api_url, settings…) même si la sérialisation évolue.
        $this->assertEqualsCanonicalizing(
            ['slug', 'name', 'logo_url', 'primary_color'],
            array_keys($result->first()->getAttributes()),
        );
    }

    public function test_list_active_retourne_une_collection_vide_sans_institution(): void
    {
        $this->assertCount(0, $this->makeService()->listActive());
    }

    public function test_current_descriptor_retourne_null_sans_tenant_resolu(): void
    {
        $this->assertNull($this->makeService()->currentDescriptor());
    }

    public function test_current_descriptor_expose_les_4_champs_du_tenant_resolu(): void
    {
        $institution = Institution::factory()->create([
            'name' => 'Alpha',
            'slug' => 'alpha',
            'is_active' => true,
            'logo_url' => null,
            'primary_color' => '#123456',
        ]);
        $tenantManager = new TenantManager();
        $tenantManager->set($institution);

        $descriptor = $this->makeService($tenantManager)->currentDescriptor();

        $this->assertSame([
            'slug' => 'alpha',
            'name' => 'Alpha',
            'logo_url' => null,
            'primary_color' => '#123456',
        ], $descriptor);
    }
}
