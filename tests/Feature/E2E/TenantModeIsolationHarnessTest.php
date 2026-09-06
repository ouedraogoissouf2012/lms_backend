<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #709 — harnais A/B klassci et C standalone, data provider de mode.
 */
final class TenantModeIsolationHarnessTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function modes(): array
    {
        return [
            'klassci' => ['klassci'],
            'standalone' => ['standalone'],
        ];
    }

    #[DataProvider('modes')]
    public function test_scope_hides_other_tenant_classes_for_mode(string $mode): void
    {
        $this->disableKlassciMiddleware();

        $ours = $this->institutionForMode($mode, 'ours');
        $theirs = $this->institutionForMode($mode, 'theirs');
        Classe::factory()->create(['institution_id' => $ours->id]);
        Classe::factory()->create(['institution_id' => $theirs->id]);

        $user = User::factory()->create([
            'institution_id' => $ours->id,
            'role' => 'etudiant',
            'last_klassci_sync' => now(),
        ]);

        $this->asTenant($user)
            ->getJson('/api/__test/tenant-scope-probe')
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    private function institutionForMode(string $mode, string $slug): Institution
    {
        return Institution::factory()->create([
            'slug' => $slug.'-'.$mode,
            'klassci_api_url' => $mode === 'klassci'
                ? 'https://klassci.example/api'
                : 'https://standalone.invalid/local',
        ]);
    }
}
