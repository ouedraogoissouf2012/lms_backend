<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seances;

use App\Models\Institution;
use App\Models\Seance;
use App\Services\Seances\LocalSeanceMatiereResolver;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Issue #517 (§1.3 gap comblé) — cette classe porte l'invariant de sécurité
 * du fast-path H3 (résolution locale du `klassci_matiere_id`, dont
 * {@see \App\Services\Seances\KlassciSeanceMatiereScanner} dépend pour éviter
 * le scan complet). Elle avait 0 test exerçant une vraie ligne trouvée en
 * base, et 0 test d'isolation tenant — corrigé ici avec le même pattern que
 * `LocalSeanceLookup` (préchargement) : 2 institutions, colonnes réelles.
 */
#[CoversClass(LocalSeanceMatiereResolver::class)]
final class LocalSeanceMatiereResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_matiere_id_for_a_synced_seance(): void
    {
        $institution = Institution::factory()->create();
        app(TenantManager::class)->set($institution);

        Seance::factory()->forInstitution($institution)->create([
            'klassci_seance_id' => 4242,
            'klassci_matiere_id' => 17,
        ]);

        $matiereId = app(LocalSeanceMatiereResolver::class)->matiereIdFor(4242);

        self::assertSame(17, $matiereId);
    }

    public function test_returns_null_when_seance_is_not_synced_locally(): void
    {
        $institution = Institution::factory()->create();
        app(TenantManager::class)->set($institution);

        $matiereId = app(LocalSeanceMatiereResolver::class)->matiereIdFor(999_999);

        self::assertNull($matiereId);
    }

    public function test_does_not_resolve_a_seance_belonging_to_another_institution(): void
    {
        $schoolA = Institution::factory()->create();
        $schoolB = Institution::factory()->create();

        app(TenantManager::class)->set($schoolA);
        Seance::factory()->forInstitution($schoolA)->create([
            'klassci_seance_id' => 4242,
            'klassci_matiere_id' => 17,
        ]);

        app(TenantManager::class)->set($schoolB);
        Seance::factory()->forInstitution($schoolB)->create([
            'klassci_seance_id' => 4242,
            'klassci_matiere_id' => 99,
        ]);

        // Toujours positionné sur schoolB : ne doit résoudre QUE sa propre
        // matière (99), jamais celle de schoolA (17) malgré le même
        // klassci_seance_id (composite unique par institution — #473).
        $matiereId = app(LocalSeanceMatiereResolver::class)->matiereIdFor(4242);

        self::assertSame(99, $matiereId);
    }
}
