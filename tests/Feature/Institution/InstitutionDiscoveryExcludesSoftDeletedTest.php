<?php

declare(strict_types=1);

namespace Tests\Feature\Institution;

use App\Models\Institution;
use App\Services\Klassci\Auth\KlassciTenantDiscovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Issue #567 / #572 (R5) — la découverte de tenants au login ignore les
 * institutions soft-deletées.
 *
 * `KlassciTenantDiscovery::loadActiveTenants` interroge `Institution::where('is_active', true)` :
 * après l'ajout de `SoftDeletes`, le `SoftDeletingScope` exclut nativement les
 * institutions supprimées → un compte orphelin ne peut plus se reconnecter.
 *
 * @see app/Services/Klassci/Auth/KlassciTenantDiscovery.php
 */
final class InstitutionDiscoveryExcludesSoftDeletedTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_ignores_soft_deleted_institutions(): void
    {
        // Tous les tenants répondent « found ».
        Http::fake(['*' => Http::response(['data' => ['found' => true]], 200)]);

        Institution::factory()->create([
            'is_active' => true,
            'slug' => 'disc-active',
            'klassci_api_url' => 'https://disc-active.klassci.test',
        ]);
        $deleted = Institution::factory()->create([
            'is_active' => true,
            'slug' => 'disc-deleted',
            'klassci_api_url' => 'https://disc-deleted.klassci.test',
        ]);
        $deleted->delete(); // soft delete

        // La ligne existe toujours (preuve du soft delete), mais est exclue des lectures.
        $this->assertNotNull(Institution::withTrashed()->find($deleted->id));

        $matches = app(KlassciTenantDiscovery::class)->findMatchingTenants('someone@example.test');
        $codes = array_column($matches, 'code');

        $this->assertContains('disc-active', $codes);
        $this->assertNotContains('disc-deleted', $codes);
    }
}
