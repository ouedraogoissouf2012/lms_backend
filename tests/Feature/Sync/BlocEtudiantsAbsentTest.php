<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Models\Institution;
use App\Services\ClasseSyncService;
use App\Services\KlassciProxyService;
use App\Services\Sync\Classes\KlassciClassesFetcher;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Le contrat du payload de `classes/{id}` : un bloc `etudiants` ABSENT n'est pas
 * une liste VIDE. « KLASSCI ne l'a pas dit » et « la classe n'a aucun élève » sont
 * deux réponses différentes, et la synchronisation les distingue, comme elle le
 * fait déjà pour `matieres`.
 *
 * - `KlassciClassesFetcher` (connexion, `GET /classes` puis le lot des détails) :
 *   la classe ne porte `etudiants` que si la réponse le contient.
 * - `ClasseSyncService::syncClasseById` : un bloc absent est journalisé, et ne
 *   passe pas pour une liste vide.
 */
final class BlocEtudiantsAbsentTest extends TestCase
{
    use RefreshDatabase;

    private const ABSENT = 'Bloc etudiants absent de la classe KLASSCI : eleves non synchronises';

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantManager::class)->set(Institution::factory()->create());
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_a_la_connexion_un_bloc_absent_ne_devient_pas_une_liste_vide(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->with('jeton', 'classes', 'GET')
                ->andReturn(['data' => [['id' => 5, 'libelle' => 'Terminale S'], ['id' => 6, 'libelle' => 'Première S']]]);
            $mock->shouldReceive('fetchManyClassesDetails')->with([5, 6], 'jeton')->andReturn([
                5 => ['data' => ['classe' => ['id' => 5, 'libelle' => 'Terminale S']]],
                6 => ['data' => ['classe' => ['id' => 6, 'libelle' => 'Première S'], 'etudiants' => []]],
            ]);
        });

        $classes = app(KlassciClassesFetcher::class)->fetch('jeton', 'coordinateur');

        self::assertArrayNotHasKey('etudiants', $classes[0], 'un bloc absent est devenu une liste');
        self::assertSame([], $classes[1]['etudiants'] ?? 'absent', 'une liste vide reçue doit rester une liste vide');
    }

    public function test_par_identifiant_un_bloc_absent_est_journalise_et_une_liste_vide_ne_l_est_pas(): void
    {
        $journal = $this->journal();
        $this->classeKlassci(7, ['classe' => ['id' => 7, 'libelle' => 'Terminale S']]);

        self::assertNotNull(app(ClasseSyncService::class)->syncClasseById(7, 'jeton'));

        $journal->shouldHaveReceived('warning')
            ->withArgs(static fn (string $message, array $contexte = []): bool => $message === self::ABSENT
                && ($contexte['klassci_classe_id'] ?? null) === 7)
            ->once();
    }

    public function test_par_identifiant_une_liste_vide_recue_n_est_pas_un_bloc_absent(): void
    {
        $journal = $this->journal();
        $this->classeKlassci(8, ['classe' => ['id' => 8, 'libelle' => 'Première S'], 'etudiants' => []]);

        self::assertNotNull(app(ClasseSyncService::class)->syncClasseById(8, 'jeton'));

        $journal->shouldNotHaveReceived('warning', [self::ABSENT, Mockery::any()]);
    }

    /** @param  array<string, mixed>  $data */
    private function classeKlassci(int $id, array $data): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($id, $data): void {
            $mock->shouldReceive('requestWithUserToken')->with('jeton', "classes/{$id}", 'GET')->andReturn(['data' => $data]);
        });
    }

    private function journal(): MockInterface
    {
        $journal = Mockery::spy(LoggerInterface::class);
        $this->app->instance(LoggerInterface::class, $journal);

        return $journal;
    }
}
