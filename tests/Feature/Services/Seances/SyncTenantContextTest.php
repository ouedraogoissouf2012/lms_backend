<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Seances;

use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\Sync\KlassciSeancesSyncService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Contexte tenant pendant la synchronisation des séances.
 *
 * ## Le défaut que ces tests ancrent
 *
 * Le job tourne HORS requête HTTP : aucun utilisateur n'est authentifié, donc
 * `KlassciConfigResolver` ne peut résoudre l'URL amont ni par le porteur du token
 * (priorité 1) ni par son institution (priorité 2). Il retombe sur la priorité 3,
 * qui lit `TenantManager::klassciConfig()`. Or le service n'y posait aucun tenant :
 * la résolution échouait sur `config('services.klassci.url')`, absent en
 * multi-tenant, et CHAQUE enseignant partait en `KlassciUnavailableException`.
 *
 * Mesuré avant correctif : `teachers_checked: 1, seances_found: 0, errors: 1`,
 * et zéro séance en base — d'où un écran manager vide, alors que l'enseignant,
 * lui, voyait ses séances (son propre chemin HTTP a un utilisateur authentifié,
 * et crée la ligne locale au passage).
 *
 * ## Pourquoi la suite existante ne le voyait pas
 *
 * Elle mocke `KlassciProxyService`, ce qui court-circuite exactement la couche
 * qui échoue. Le mock est ici retourné contre le défaut : il ne sert plus à
 * simuler l'amont, mais à OBSERVER le tenant courant au moment précis de l'appel.
 *
 * @see app/Services/Klassci/KlassciConfigResolver.php
 * @see app/Services/Seances/Sync/KlassciSeancesSyncService.php
 */
final class SyncTenantContextTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    private function teacherIn(Institution $institution, string $token, int $klassciId): User
    {
        return User::factory()->for($institution)->create([
            'role' => 'enseignant',
            'klassci_id' => $klassciId,
            'klassci_token' => $token,
        ]);
    }

    /**
     * Capture le tenant courant à chaque appel amont, par token utilisé.
     *
     * @param  array<string, list<int|null>>  $seen
     */
    private function spyOnTenantDuringCalls(array &$seen): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use (&$seen): void {
            $mock->shouldReceive('requestWithUserToken')
                ->andReturnUsing(function (string $token) use (&$seen): array {
                    $seen[$token][] = app(TenantManager::class)->id();

                    return ['data' => []];
                });
            $mock->shouldReceive('fetchManyMatieresDetails')->andReturn([]);
            $mock->shouldReceive('fetchManyClassesDetails')->andReturn([]);
        });
    }

    public function test_tenant_is_resolved_while_calling_klassci(): void
    {
        $institution = Institution::factory()->create();
        $this->teacherIn($institution, 'token-a', 1001);

        /** @var array<string, list<int|null>> $seen */
        $seen = [];
        $this->spyOnTenantDuringCalls($seen);

        // Départ SANS tenant : c'est l'état réel d'un job de queue.
        app(TenantManager::class)->reset();
        app(KlassciSeancesSyncService::class)->sync();

        self::assertNotEmpty($seen, 'Aucun appel amont émis : le service a échoué avant.');
        self::assertSame(
            [$institution->id],
            array_values(array_unique($seen['token-a'] ?? [])),
            'Le tenant doit être posé pendant l\'appel, sinon l\'URL KLASSCI est introuvable.'
        );
    }

    public function test_each_teacher_is_synced_under_his_own_tenant(): void
    {
        // Deux établissements : le contexte doit SUIVRE l'enseignant traité. Sans
        // réinitialisation entre les deux, la résolution reste figée sur le premier
        // tenant et le second recevrait l'URL — donc le trafic — du premier.
        $institutionA = Institution::factory()->create(['slug' => 'sync-tenant-a']);
        $institutionB = Institution::factory()->create(['slug' => 'sync-tenant-b']);
        $this->teacherIn($institutionA, 'token-a', 1001);
        $this->teacherIn($institutionB, 'token-b', 1002);

        /** @var array<string, list<int|null>> $seen */
        $seen = [];
        $this->spyOnTenantDuringCalls($seen);

        app(TenantManager::class)->reset();
        app(KlassciSeancesSyncService::class)->sync();

        self::assertSame([$institutionA->id], array_values(array_unique($seen['token-a'] ?? [])));
        self::assertSame([$institutionB->id], array_values(array_unique($seen['token-b'] ?? [])));
    }
}
