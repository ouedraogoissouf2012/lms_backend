<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seances;

use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciSeanceMatiereScanner;
use App\Services\Seances\LocalSeanceMatiereResolver;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Issue #517 (H3) — verrouille l'élimination du N+1 HTTP sur
 * `KlassciSeanceLookupService` : la résolution locale court-circuite le scan
 * quand possible, et le fallback est TOUJOURS batché (jamais séquentiel).
 */
#[CoversClass(KlassciSeanceMatiereScanner::class)]
final class KlassciSeanceMatiereScannerTest extends TestCase
{
    private const TOKEN = 'klassci-token';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_local_fast_path_issues_a_single_targeted_http_call(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('requestWithUserToken')
            ->once()
            ->with(self::TOKEN, 'matieres/17', 'GET')
            ->andReturn([
                'data' => [
                    'matiere' => ['id' => 17, 'nom' => 'Maths'],
                    'seances_programmees' => [['id' => 44, 'titre' => 'Algebre']],
                ],
            ]);
        $klassci->shouldNotReceive('fetchManyMatieresDetails');

        $resolver = $this->mockResolver();
        $resolver->shouldReceive('matiereIdFor')->once()->with(44)->andReturn(17);

        $matieresById = [17 => ['id' => 17, 'nom' => 'Maths'], 22 => ['id' => 22, 'nom' => 'Physique']];

        [$seance, $matiereDetails, $fallback] = $this->scanner($klassci, $resolver)->scan($matieresById, 44, self::TOKEN);

        self::assertSame(44, $seance['id'] ?? null);
        self::assertSame(17, $matiereDetails['data']['matiere']['id'] ?? null);
        self::assertSame(['id' => 17, 'nom' => 'Maths'], $fallback);
    }

    public function test_local_fast_path_falls_back_to_batched_scan_when_the_targeted_call_fails(): void
    {
        // Le GET ciblé du fast path peut échouer (timeout, matière réassignée
        // côté KLASSCI) : le scan doit rester résilient et retomber sur le
        // batch, comme pour une désynchronisation de cache (pas de 500).
        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('requestWithUserToken')
            ->once()
            ->with(self::TOKEN, 'matieres/17', 'GET')
            ->andThrow(new \RuntimeException('KLASSCI indisponible'));
        $klassci->shouldReceive('fetchManyMatieresDetails')
            ->once()
            ->with([17, 22], self::TOKEN)
            ->andReturn([22 => ['data' => ['seances_programmees' => [['id' => 44]]]]]);

        $resolver = $this->mockResolver();
        $resolver->shouldReceive('matiereIdFor')->once()->with(44)->andReturn(17);

        $matieresById = [17 => ['id' => 17], 22 => ['id' => 22]];

        [$seance] = $this->scanner($klassci, $resolver)->scan($matieresById, 44, self::TOKEN);

        self::assertSame(44, $seance['id'] ?? null);
    }

    public function test_falls_back_to_batched_scan_when_local_resolution_is_absent(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldNotReceive('requestWithUserToken');
        $klassci->shouldReceive('fetchManyMatieresDetails')
            ->once()
            ->with([17, 22], self::TOKEN)
            ->andReturn([
                17 => ['data' => ['matiere' => ['id' => 17], 'seances_programmees' => [['id' => 1]]]],
                22 => ['data' => ['matiere' => ['id' => 22], 'seances_programmees' => [['id' => 44, 'titre' => 'Optique']]]],
            ]);

        $resolver = $this->mockResolver();
        $resolver->shouldReceive('matiereIdFor')->once()->with(44)->andReturn(null);

        $matieresById = [17 => ['id' => 17], 22 => ['id' => 22]];

        [$seance, $matiereDetails] = $this->scanner($klassci, $resolver)->scan($matieresById, 44, self::TOKEN);

        self::assertSame(44, $seance['id'] ?? null);
        self::assertSame(22, $matiereDetails['data']['matiere']['id'] ?? null);
    }

    public function test_local_resolution_outside_the_accessible_matiere_set_is_rejected(): void
    {
        // R3 — évite un IDOR : un id résolu localement mais hors du set
        // role-specific accessible ne doit JAMAIS déclencher un fetch ciblé.
        $klassci = $this->mockKlassci();
        $klassci->shouldNotReceive('requestWithUserToken');
        $klassci->shouldReceive('fetchManyMatieresDetails')
            ->once()
            ->with([17], self::TOKEN)
            ->andReturn([17 => ['data' => ['seances_programmees' => [['id' => 44]]]]]);

        $resolver = $this->mockResolver();
        $resolver->shouldReceive('matiereIdFor')->once()->with(44)->andReturn(999);

        [$seance] = $this->scanner($klassci, $resolver)->scan([17 => ['id' => 17]], 44, self::TOKEN);

        self::assertSame(44, $seance['id'] ?? null);
    }

    public function test_stale_local_resolution_falls_back_to_batched_scan(): void
    {
        // Cache local désynchronisé : la séance n'est plus dans la matière
        // résolue localement -> fallback batch au lieu d'un 404 prématuré.
        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('requestWithUserToken')
            ->once()
            ->with(self::TOKEN, 'matieres/17', 'GET')
            ->andReturn(['data' => ['seances_programmees' => []]]);
        $klassci->shouldReceive('fetchManyMatieresDetails')
            ->once()
            ->with([17, 22], self::TOKEN)
            ->andReturn([22 => ['data' => ['seances_programmees' => [['id' => 44]]]]]);

        $resolver = $this->mockResolver();
        $resolver->shouldReceive('matiereIdFor')->once()->with(44)->andReturn(17);

        $matieresById = [17 => ['id' => 17], 22 => ['id' => 22]];

        [$seance] = $this->scanner($klassci, $resolver)->scan($matieresById, 44, self::TOKEN);

        self::assertSame(44, $seance['id'] ?? null);
    }

    public function test_empty_candidate_set_returns_not_found_without_any_http_call(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldNotReceive('requestWithUserToken');
        $klassci->shouldNotReceive('fetchManyMatieresDetails');

        // Aucun candidat : la résolution locale est court-circuitée (#517
        // efficacité — pas de requête SQL gaspillée quand le résultat est
        // de toute façon rejeté faute de candidats).
        $resolver = $this->mockResolver();
        $resolver->shouldNotReceive('matiereIdFor');

        $result = $this->scanner($klassci, $resolver)->scan([], 44, self::TOKEN);

        self::assertSame([null, [], []], $result);
    }

    private function scanner(KlassciProxyService $klassci, LocalSeanceMatiereResolver $resolver): KlassciSeanceMatiereScanner
    {
        return new KlassciSeanceMatiereScanner($klassci, $resolver);
    }

    private function mockKlassci(): KlassciProxyService&MockInterface
    {
        /** @var KlassciProxyService&MockInterface $klassci */
        $klassci = Mockery::mock(KlassciProxyService::class);

        return $klassci;
    }

    private function mockResolver(): LocalSeanceMatiereResolver&MockInterface
    {
        /** @var LocalSeanceMatiereResolver&MockInterface $resolver */
        $resolver = Mockery::mock(LocalSeanceMatiereResolver::class);

        return $resolver;
    }
}
