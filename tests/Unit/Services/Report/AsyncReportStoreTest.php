<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Report;

use App\Services\Report\AsyncReportStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * La durée de vie d'un rapport asynchrone : 24 heures, posée par le magasin à
 * chaque écriture. Le test de contrat des rapports simule l'expiration en
 * retirant l'entrée du cache (le temps figé de Laravel n'agit pas sur Redis) :
 * la DURÉE est éprouvée ici, sur le dépôt de cache lui-même.
 */
final class AsyncReportStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_chaque_ecriture_pose_une_duree_de_24_heures(): void
    {
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('put')->once()->with('async_report_r1', Mockery::type('array'), 86400);
        $cache->shouldReceive('get')->once()->with('async_report_r1')->andReturn(['id' => 'r1', 'status' => 'pending']);
        $cache->shouldReceive('put')->once()->with('async_report_r1', Mockery::on(
            static fn (array $entree): bool => $entree['status'] === 'running',
        ), 86400);

        $magasin = new AsyncReportStore($cache);
        $magasin->pending('r1', 7, 'grades');
        $magasin->running('r1');

        self::assertTrue(true, 'les attentes Mockery portent la preuve');
    }

    public function test_une_entree_absente_du_cache_est_un_rapport_absent(): void
    {
        $magasin = new AsyncReportStore(new Repository(new ArrayStore()));

        self::assertNull($magasin->get('inconnu'));
        $magasin->running('inconnu');
        self::assertNull($magasin->get('inconnu'), 'un rapport absent ne renaît pas par un changement d\'état');
    }
}
