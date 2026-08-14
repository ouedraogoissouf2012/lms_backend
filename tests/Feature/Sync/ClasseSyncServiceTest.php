<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Models\Classe;
use App\Models\Institution;
use App\Services\ClasseSyncService;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

final class ClasseSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_klassci_id_isolated_across_institutions(): void
    {
        $schoolA = Institution::factory()->create();
        $schoolB = Institution::factory()->create();

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('token-a', 'classes', 'GET')
                ->once()
                ->andReturn(['data' => [['id' => 5, 'libelle' => 'Classe A']]]);
            $mock->shouldReceive('fetchManyClassesDetails')
                ->with([5], 'token-a')
                ->once()
                ->andReturn([5 => ['data' => ['classe' => ['id' => 5, 'libelle' => 'Classe A']]]]);

            $mock->shouldReceive('requestWithUserToken')
                ->with('token-b', 'classes', 'GET')
                ->once()
                ->andReturn(['data' => [['id' => 5, 'libelle' => 'Classe B']]]);
            $mock->shouldReceive('fetchManyClassesDetails')
                ->with([5], 'token-b')
                ->once()
                ->andReturn([5 => ['data' => ['classe' => ['id' => 5, 'libelle' => 'Classe B']]]]);
        });

        $tenant = app(TenantManager::class);

        $tenant->set($schoolA);
        $statsA = app(ClasseSyncService::class)->syncUserClasses('token-a', 'coordinateur');

        $tenant->set($schoolB);
        $statsB = app(ClasseSyncService::class)->syncUserClasses('token-b', 'coordinateur');

        $this->assertSame(1, $statsA['classes_created']);
        $this->assertSame(1, $statsB['classes_created']);
        $this->assertSame(2, Classe::withoutGlobalScope('institution')->where('klassci_id', 5)->count());
        $this->assertSame(
            'Classe A',
            Classe::withoutGlobalScope('institution')
                ->where('institution_id', $schoolA->id)
                ->where('klassci_id', 5)
                ->value('libelle'),
        );
        $this->assertSame(
            'Classe B',
            Classe::withoutGlobalScope('institution')
                ->where('institution_id', $schoolB->id)
                ->where('klassci_id', 5)
                ->value('libelle'),
        );
    }

    public function test_sync_updates_existing_class_inside_same_institution(): void
    {
        $school = Institution::factory()->create();
        app(TenantManager::class)->set($school);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('token', 'classes', 'GET')
                ->twice()
                ->andReturn(
                    ['data' => [['id' => 7, 'libelle' => 'Ancien nom']]],
                    ['data' => [['id' => 7, 'libelle' => 'Nouveau nom']]],
                );
            $mock->shouldReceive('fetchManyClassesDetails')
                ->with([7], 'token')
                ->twice()
                ->andReturn(
                    [7 => ['data' => ['classe' => ['id' => 7, 'libelle' => 'Ancien nom']]]],
                    [7 => ['data' => ['classe' => ['id' => 7, 'libelle' => 'Nouveau nom']]]],
                );
        });

        $service = app(ClasseSyncService::class);
        $service->syncUserClasses('token', 'coordinateur');
        $stats = $service->syncUserClasses('token', 'coordinateur');

        $this->assertSame(1, $stats['classes_updated']);
        $this->assertSame(
            'Nouveau nom',
            Classe::withoutGlobalScope('institution')
                ->where('institution_id', $school->id)
                ->where('klassci_id', 7)
                ->value('libelle'),
        );
        $this->assertSame(1, Classe::withoutGlobalScope('institution')->where('klassci_id', 7)->count());
    }
}
