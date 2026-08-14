<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seances;

use App\Services\KlassciProxyService;
use App\Services\Seances\Sync\TeacherMatieresResolver;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Issue #515 — verrouille l'élimination du N+1 HTTP : `TeacherMatieresResolver`
 * doit toujours résoudre les matières d'un enseignant via UN SEUL appel batch,
 * et distinguer les matières résolues des matières en échec (`failedMatiereIds`
 * — restaure le signal `stats->errors` perdu par le passage au batch, cf.
 * finding de revue documenté dans design.md §3).
 */
#[CoversClass(TeacherMatieresResolver::class)]
final class TeacherMatieresResolverTest extends TestCase
{
    private const TOKEN = 'teacher-token';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_resolves_all_matieres_via_a_single_batch_call(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('fetchManyMatieresDetails')
            ->once()
            ->with([10, 20], self::TOKEN)
            ->andReturn([
                10 => ['data' => ['seances_programmees' => [['id' => 1]]]],
                20 => ['data' => ['seances_programmees' => [['id' => 2]]]],
            ]);

        $matieresList = [
            ['id' => 10, 'nom' => 'Maths'],
            ['id' => 20, 'nom' => 'Physique'],
        ];

        $resolution = $this->resolver($klassci)->resolve($matieresList, self::TOKEN);

        self::assertCount(2, $resolution->resolved);
        self::assertSame([], $resolution->failedMatiereIds);
        self::assertSame(['id' => 10, 'nom' => 'Maths'], $resolution->resolved[10]->matiere);
        self::assertSame(['data' => ['seances_programmees' => [['id' => 1]]]], $resolution->resolved[10]->details);
    }

    public function test_matieres_without_an_exploitable_id_are_excluded_before_the_batch_call(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('fetchManyMatieresDetails')
            ->once()
            ->with([10], self::TOKEN)
            ->andReturn([10 => ['data' => ['seances_programmees' => []]]]);

        $matieresList = [
            ['id' => 10, 'nom' => 'Maths'],
            ['nom' => 'Sans ID'],
            ['id' => null, 'nom' => 'ID null'],
        ];

        $resolution = $this->resolver($klassci)->resolve($matieresList, self::TOKEN);

        self::assertCount(1, $resolution->resolved);
        self::assertArrayHasKey(10, $resolution->resolved);
        // Absence d'ID exploitable : écartée silencieusement, jamais comptée
        // comme échec (comportement inchangé, distinct de failedMatiereIds).
        self::assertSame([], $resolution->failedMatiereIds);
    }

    public function test_empty_matieres_list_short_circuits_without_any_http_call(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldNotReceive('fetchManyMatieresDetails');

        $resolution = $this->resolver($klassci)->resolve([], self::TOKEN);

        self::assertSame([], $resolution->resolved);
        self::assertSame([], $resolution->failedMatiereIds);
    }

    public function test_matiere_omitted_from_batch_result_is_reported_as_failed(): void
    {
        // Tolérance aux échecs partiels du batch fetcher (log + omission,
        // pas de throw) — cf. KlassciBatchFetcher::persistBatchResponses.
        // La matière omise doit être distinguée via failedMatiereIds (et non
        // silencieusement disparaître), pour que l'appelant puisse restaurer
        // le comptage stats->errors qu'assurait l'ancien code séquentiel.
        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('fetchManyMatieresDetails')
            ->once()
            ->with([10, 20], self::TOKEN)
            ->andReturn([20 => ['data' => ['seances_programmees' => []]]]);

        $matieresList = [
            ['id' => 10, 'nom' => 'Maths'],
            ['id' => 20, 'nom' => 'Physique'],
        ];

        $resolution = $this->resolver($klassci)->resolve($matieresList, self::TOKEN);

        self::assertCount(1, $resolution->resolved);
        self::assertArrayNotHasKey(10, $resolution->resolved);
        self::assertArrayHasKey(20, $resolution->resolved);
        self::assertSame([10], $resolution->failedMatiereIds);
    }

    private function resolver(KlassciProxyService $klassci): TeacherMatieresResolver
    {
        return new TeacherMatieresResolver($klassci);
    }

    private function mockKlassci(): KlassciProxyService&MockInterface
    {
        /** @var KlassciProxyService&MockInterface $klassci */
        $klassci = Mockery::mock(KlassciProxyService::class);

        return $klassci;
    }
}
