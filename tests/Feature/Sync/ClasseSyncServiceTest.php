<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Models\Classe;
use App\Models\Institution;
use App\Services\ClasseSyncService;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ClasseSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * #1357 : `classes.effectif` est une CAPACITÉ (#1115). KLASSCI la porte dans
     * `places_totales` ; mesuré sur de vraies réponses (base de dev, 2 classes, forme liste) :
     * `places_totales` et `places_occupees`, ni `effectif` ni `places`. Le dépôt nomme
     * « effectif » le nombre d'INSCRITS (`KlassciPayload`), qui ne borne rien.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function capacites(): iterable
    {
        yield 'la forme mesurée' => [['places_totales' => 30, 'places_occupees' => 6], 30];
        yield 'places seul' => [['places' => 30], 30];
        yield 'un effectif seul n est pas une capacité' => [['effectif' => 6], 0];
        yield 'places_totales nul, effectif présent' => [['places_totales' => null, 'effectif' => 6], 0];
        yield 'une capacité non entière' => [['places_totales' => 'abc'], 0];
        yield 'une capacité négative' => [['places_totales' => -1], 0];
    }

    /** @param array<string, mixed> $champs */
    #[DataProvider('capacites')]
    public function test_la_capacite_lue_a_la_connexion(array $champs, int $attendue): void
    {
        $classe = ['id' => 9, 'libelle' => '6e A'] + $champs;
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($classe): void {
            $mock->shouldReceive('requestWithUserToken')->with('token', 'classes', 'GET')->once()->andReturn(['data' => [$classe]]);
            $mock->shouldReceive('fetchManyClassesDetails')->with([9], 'token')->once()->andReturn([9 => ['data' => ['classe' => $classe]]]);
        });
        $ecole = $this->ecoleCourante();

        app(ClasseSyncService::class)->syncUserClasses('token', 'coordinateur');

        $this->assertCapacite($ecole, $attendue);
    }

    /** @param array<string, mixed> $champs */
    #[DataProvider('capacites')]
    public function test_la_capacite_lue_par_identifiant(array $champs, int $attendue): void
    {
        $classe = ['id' => 9, 'libelle' => '6e A'] + $champs;
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($classe): void {
            $mock->shouldReceive('requestWithUserToken')->with('token', 'classes/9', 'GET')->once()->andReturn(['data' => ['classe' => $classe]]);
        });
        $ecole = $this->ecoleCourante();

        app(ClasseSyncService::class)->syncClasseById(9, 'token');

        $this->assertCapacite($ecole, $attendue);
    }

    private function ecoleCourante(): Institution
    {
        $ecole = Institution::factory()->create();
        app(TenantManager::class)->set($ecole);

        return $ecole;
    }

    /**
     * Lue en base et comparée en TEXTE : un `(int)` ferait passer pour 0 le « abc » que
     * SQLite garde tel quel dans une colonne entière.
     */
    private function assertCapacite(Institution $ecole, int $attendue): void
    {
        $ecrite = DB::table('classes')->where('institution_id', $ecole->id)->where('klassci_id', 9)->value('effectif');

        $this->assertNotNull($ecrite, 'la classe doit être synchronisée');
        $this->assertSame((string) $attendue, is_scalar($ecrite) ? (string) $ecrite : null);
    }

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
