<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sync\Classes;

use App\Services\KlassciProxyService;
use App\Services\Sync\Classes\KlassciClassesFetcher;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * Issue #517 (H5) — verrouille le batch des deux boucles HTTP séquentielles
 * de `KlassciClassesFetcher` (`classes/{id}` et `matieres/{id}`).
 */
#[CoversClass(KlassciClassesFetcher::class)]
final class KlassciClassesFetcherTest extends TestCase
{
    private const TOKEN = 'klassci-token';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_classes_endpoint_details_are_fetched_in_a_single_batch_call(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('requestWithUserToken')
            ->once()
            ->with(self::TOKEN, 'classes', 'GET')
            ->andReturn(['data' => [
                ['id' => 5, 'libelle' => 'Classe A'],
                ['id' => 6, 'libelle' => 'Classe B'],
            ]]);
        $klassci->shouldNotReceive('requestWithUserToken')->with(self::TOKEN, 'classes/5', 'GET');
        $klassci->shouldNotReceive('requestWithUserToken')->with(self::TOKEN, 'classes/6', 'GET');
        $klassci->shouldReceive('fetchManyClassesDetails')
            ->once()
            ->with([5, 6], self::TOKEN)
            ->andReturn([
                5 => ['data' => ['classe' => ['id' => 5, 'libelle' => 'Classe A'], 'etudiants' => [['id' => 1]]]],
                // 6 absent du map -> simule un échec partiel du pool.
            ]);

        $classes = $this->fetcher($klassci)->fetch(self::TOKEN, 'coordinateur');

        self::assertSame(['id' => 5, 'libelle' => 'Classe A', 'etudiants' => [['id' => 1]]], $classes[0]);
        // Fallback vers les infos basiques pour l'id absent du batch (comportement préservé).
        self::assertSame(['id' => 6, 'libelle' => 'Classe B'], $classes[1]);
    }

    public function test_batch_failure_degrades_to_basic_classes_instead_of_triggering_role_fallback(): void
    {
        // Un échec du batch de détails (pas un échec par id — déjà toléré en
        // interne) ne doit PAS être confondu par fetch() avec un échec de
        // `/classes` elle-même : sinon les classes déjà listées sont perdues
        // et le fallback par rôle (potentiellement le mauvais rôle) se déclenche.
        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('requestWithUserToken')
            ->once()
            ->with(self::TOKEN, 'classes', 'GET')
            ->andReturn(['data' => [['id' => 5, 'libelle' => 'Classe A']]]);
        $klassci->shouldReceive('fetchManyClassesDetails')
            ->once()
            ->with([5], self::TOKEN)
            ->andThrow(new \RuntimeException('KLASSCI indisponible'));
        $klassci->shouldNotReceive('requestWithUserToken')->with(self::TOKEN, 'me/teacher-dashboard', 'GET');

        $classes = $this->fetcher($klassci)->fetch(self::TOKEN, 'coordinateur');

        self::assertSame([['id' => 5, 'libelle' => 'Classe A']], $classes);
    }

    public function test_teacher_fallback_batches_matiere_details_and_dedupes_classes(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('requestWithUserToken')
            ->once()
            ->with(self::TOKEN, 'classes', 'GET')
            ->andThrow(new \Exception('403 Forbidden'));
        $klassci->shouldReceive('requestWithUserToken')
            ->once()
            ->with(self::TOKEN, 'me/teacher-dashboard', 'GET')
            ->andReturn(['data' => ['matieres' => [
                ['id' => 10, 'nom' => 'Maths'],
                ['id' => 11, 'nom' => 'Physique'],
            ]]]);
        $klassci->shouldNotReceive('requestWithUserToken')->with(self::TOKEN, 'matieres/10', 'GET');
        $klassci->shouldNotReceive('requestWithUserToken')->with(self::TOKEN, 'matieres/11', 'GET');
        $klassci->shouldReceive('fetchManyMatieresDetails')
            ->once()
            ->with([10, 11], self::TOKEN)
            ->andReturn([
                10 => ['data' => ['classe' => ['id' => 30, 'libelle' => 'Terminale S1']]],
                11 => ['data' => ['classe' => ['id' => 30, 'libelle' => 'Terminale S1']]],
            ]);

        $classes = $this->fetcher($klassci)->fetch(self::TOKEN, 'enseignant');

        self::assertCount(1, $classes, 'La classe partagée par les 2 matières doit être dédupliquée.');
        self::assertSame(30, $classes[0]['id']);
    }

    private function fetcher(KlassciProxyService $klassci): KlassciClassesFetcher
    {
        return new KlassciClassesFetcher($klassci, new NullLogger);
    }

    private function mockKlassci(): KlassciProxyService&MockInterface
    {
        /** @var KlassciProxyService&MockInterface $klassci */
        $klassci = Mockery::mock(KlassciProxyService::class);

        return $klassci;
    }
}
