<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Matiere;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Matiere\MatiereSeancesFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Issue #517 (H4) — verrouille l'élimination du N+1 HTTP+SQL de
 * `MatiereSeancesFetcher::enrichSeances` / `filterHiddenAndArchivedForStudent`.
 * Pattern baseline-vs-afterGrowth (cf. `UpcomingSeancesNoNPlusOneTest`, #476).
 */
#[CoversClass(MatiereSeancesFetcher::class)]
final class MatiereSeancesFetcherTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'fake-token';

    private const MATIERE_ID = 1;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::factory()->create();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_coordinator_path_query_count_is_constant_as_seances_grow(): void
    {
        $coordinator = $this->userWithRole('coordinateur');

        $payloadsFew = $this->seedSeances(3, 10_000, 500);
        $payloadsMany = $this->seedSeances(30, 20_000, 500);

        $baseline = $this->countLocalSeancesQueries(fn () => $this->fetchOnly($coordinator, $payloadsFew));
        $afterGrowth = $this->countLocalSeancesQueries(fn () => $this->fetchOnly($coordinator, $payloadsMany));

        self::assertSame(
            $baseline,
            $afterGrowth,
            "N+1 SQL détecté : {$baseline} requêtes à 3 séances vs {$afterGrowth} à 30 séances.",
        );
        self::assertSame(1, $baseline, 'Le chemin coordinateur doit émettre exactement 1 requête locale (préchargement).');
    }

    public function test_enrichment_batches_classe_details_in_a_single_pool_call_for_all_seances(): void
    {
        $coordinator = $this->userWithRole('coordinateur');
        $payloads = $this->seedSeances(5, 30_000, 700);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fetchManyClassesDetails')
                ->once()
                ->with([700], self::TOKEN)
                ->andReturn([700 => ['data' => ['classe' => ['places_occupees' => 27]]]]);
        });

        $result = app(MatiereSeancesFetcher::class)->fetchSeancesForUser(
            $coordinator,
            self::MATIERE_ID,
            self::TOKEN,
            ['seances_programmees' => $payloads],
        );

        self::assertCount(5, $result['seances_enrichies']);
        foreach ($result['seances_enrichies'] as $seance) {
            self::assertSame(27, $seance['classe_effectif']);
        }
    }

    public function test_student_path_hides_archived_seance_via_preloaded_state(): void
    {
        $student = $this->userWithRole('etudiant');

        Seance::factory()->forInstitution($this->institution)->create([
            'klassci_seance_id' => 900_001,
            'is_active' => true,
        ]);
        Seance::factory()->forInstitution($this->institution)->create([
            'klassci_seance_id' => 900_002,
            'is_active' => false,
        ]);

        $payloads = [
            $this->seancePayload(900_001, 700),
            $this->seancePayload(900_002, 700),
        ];

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($payloads): void {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with(self::TOKEN, 'me/dashboard', 'GET')
                ->andReturn(['data' => ['matieres' => [['id' => self::MATIERE_ID]]]]);
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with(self::TOKEN, 'matieres/'.self::MATIERE_ID, 'GET')
                ->andReturn(['data' => ['seances_programmees' => $payloads]]);
            $mock->shouldReceive('fetchManyClassesDetails')
                ->once()
                ->with([700], self::TOKEN)
                ->andReturn([]);
        });

        $result = app(MatiereSeancesFetcher::class)->fetchSeancesForUser(
            $student,
            self::MATIERE_ID,
            self::TOKEN,
            [],
        );

        self::assertCount(1, $result['seances']);
        self::assertSame(900_001, $result['seances'][0]['id']);
    }

    public function test_student_path_reuses_already_fetched_matiere_data_instead_of_refetching(): void
    {
        // MatiereInfoFetcher appelle DEJA `matieres/{id}` avant que
        // l'orchestrateur ne transmette son resultat ici via `$matiereData`.
        // Le reappeler est un N+1 HTTP garanti (meme endpoint, memes params,
        // dans la MEME requete) — §1.4 PRODUCTION_STANDARDS.md. Ce test
        // verrouille l'ELIMINATION : quand `$matiereData` porte deja
        // `seances_programmees`, aucun second appel `matieres/{id}` n'a lieu.
        $student = $this->userWithRole('etudiant');

        Seance::factory()->forInstitution($this->institution)->create([
            'klassci_seance_id' => 900_003,
            'is_active' => true,
        ]);

        $payloads = [$this->seancePayload(900_003, 700)];

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($payloads): void {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with(self::TOKEN, 'me/dashboard', 'GET')
                ->andReturn(['data' => ['matieres' => [['id' => self::MATIERE_ID]]]]);
            // AUCUNE attente sur 'matieres/'.self::MATIERE_ID : Mockery echoue
            // le test si ce second appel a lieu (mock non configure pour lui).
            $mock->shouldReceive('fetchManyClassesDetails')
                ->once()
                ->with([700], self::TOKEN)
                ->andReturn([]);
        });

        $result = app(MatiereSeancesFetcher::class)->fetchSeancesForUser(
            $student,
            self::MATIERE_ID,
            self::TOKEN,
            ['seances_programmees' => $payloads],
        );

        self::assertCount(1, $result['seances']);
        self::assertSame(900_003, $result['seances'][0]['id']);
    }

    public function test_teacher_path_also_reuses_already_fetched_matiere_data(): void
    {
        // Meme elimination que le test etudiant ci-dessus, sur le chemin
        // enseignant (`me/teacher-dashboard`) — meme methode privee partagee,
        // mais sans couverture dediee avant ce test : les deux appelants de
        // `fetchSeancesFromDashboard` doivent beneficier de la reutilisation,
        // pas seulement celui qui avait deja un test.
        $teacher = $this->userWithRole('enseignant');

        Seance::factory()->forInstitution($this->institution)->create([
            'klassci_seance_id' => 900_004,
            'is_active' => true,
        ]);

        $payloads = [$this->seancePayload(900_004, 700)];

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($payloads): void {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with(self::TOKEN, 'me/teacher-dashboard', 'GET')
                ->andReturn(['data' => ['matieres' => [['id' => self::MATIERE_ID]]]]);
            // AUCUNE attente sur 'matieres/'.self::MATIERE_ID ici non plus.
            $mock->shouldReceive('fetchManyClassesDetails')
                ->once()
                ->with([700], self::TOKEN)
                ->andReturn([]);
        });

        $result = app(MatiereSeancesFetcher::class)->fetchSeancesForUser(
            $teacher,
            self::MATIERE_ID,
            self::TOKEN,
            ['seances_programmees' => $payloads],
        );

        self::assertCount(1, $result['seances']);
        self::assertSame(900_004, $result['seances'][0]['id']);
    }

    public function test_reuse_does_not_refetch_when_matiere_genuinely_has_zero_seances(): void
    {
        // Cas limite qui piege une implementation naive (`!empty($seances)`
        // au lieu de `!is_array($seances)`) : `seances_programmees` PRESENT
        // mais VIDE est une mesure valide (0 seance programmee), pas une
        // absence — ne doit PAS declencher le repli vers un second appel.
        $student = $this->userWithRole('etudiant');

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with(self::TOKEN, 'me/dashboard', 'GET')
                ->andReturn(['data' => ['matieres' => [['id' => self::MATIERE_ID]]]]);
            // AUCUNE attente sur fetchManyClassesDetails : sans classe a
            // resoudre, fetchClassesDetails() court-circuite avant l'appel.
        });

        $result = app(MatiereSeancesFetcher::class)->fetchSeancesForUser(
            $student,
            self::MATIERE_ID,
            self::TOKEN,
            ['seances_programmees' => []],
        );

        self::assertSame([], $result['seances']);
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->for($this->institution)->create([
            'role' => $role,
            'klassci_id' => 555,
            'klassci_token' => self::TOKEN,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function seedSeances(int $count, int $idOffset, int $classeId): array
    {
        $payloads = [];
        for ($i = 1; $i <= $count; $i++) {
            $klassciSeanceId = $idOffset + $i;
            Seance::factory()->forInstitution($this->institution)->create([
                'klassci_seance_id' => $klassciSeanceId,
                'klassci_classe_id' => $classeId,
                'is_active' => true,
            ]);
            $payloads[] = $this->seancePayload($klassciSeanceId, $classeId);
        }

        return $payloads;
    }

    /**
     * Exécute UNIQUEMENT `fetchSeancesForUser()` (coordinateur — pas d'appel
     * dashboard), sur des séances déjà créées. C'est ce seul appel qui est
     * mesuré par `countLocalSeancesQueries`.
     *
     * @param  list<array<string, mixed>>  $payloads
     */
    private function fetchOnly(User $user, array $payloads): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($payloads): void {
            $classeIds = collect($payloads)->pluck('classe.id')->unique()->values()->all();
            $mock->shouldReceive('fetchManyClassesDetails')
                ->once()
                ->with($classeIds, self::TOKEN)
                ->andReturn([]);
        });

        app(MatiereSeancesFetcher::class)->fetchSeancesForUser(
            $user,
            self::MATIERE_ID,
            self::TOKEN,
            ['seances_programmees' => $payloads],
        );
    }

    private function countLocalSeancesQueries(callable $run): int
    {
        DB::enableQueryLog();
        $run();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $queries->filter(fn (string $q): bool => str_contains($q, 'seances') || str_contains($q, 'seance_user_hidden'))->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function seancePayload(int $id, int $classeId): array
    {
        return [
            'id' => $id,
            'classe' => ['id' => $classeId, 'nom' => 'Classe '.$classeId],
            'programmation' => [
                'date' => '2026-08-15',
                'heure_debut' => '2026-08-15T09:00:00.000000Z',
                'heure_fin' => '2026-08-15T10:00:00.000000Z',
                'salle' => 'Salle 1',
            ],
        ];
    }
}
