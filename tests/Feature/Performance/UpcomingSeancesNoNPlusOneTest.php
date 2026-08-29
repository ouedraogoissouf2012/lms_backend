<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\UpcomingSeancesFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Issue #476 — §1.4 zéro N+1 SQL sur le chemin « séances à venir » (non-manager).
 *
 * Verrouille l'absence de N+1 : le nombre de requêtes SQL frappant `seances` et
 * `seance_user_hidden` pendant `UpcomingSeancesFetcher::fetch()` DOIT rester
 * CONSTANT quand le volume de séances croît (pattern « baseline vs afterGrowth »,
 * cf. SeanceParticipantsCountTest / TeacherDashboardServiceTest).
 *
 * AVANT le fix, ce test échoue : les 3 N+1 (filterSeances lookup + isHidden,
 * enrichWithVisio lookup) font croître le nombre de requêtes avec le volume.
 * APRÈS le fix (pré-chargement whereIn), il reste constant (2 pour un étudiant,
 * 1 pour un enseignant).
 */
final class UpcomingSeancesNoNPlusOneTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_student_path_query_count_is_constant_as_seances_grow(): void
    {
        $student = $this->userWithRole('etudiant');

        // Setup HORS mesure (les INSERT factory ne doivent pas être comptés).
        // Plages d'IDs disjointes → pas de collision sur l'unique composite #473.
        $payloadsFew = $this->seedSeances(3, 10_000);
        $payloadsMany = $this->seedSeances(30, 20_000);

        $baseline = $this->countLocalSeancesQueries(fn () => $this->fetchOnly($student, $payloadsFew));
        $afterGrowth = $this->countLocalSeancesQueries(fn () => $this->fetchOnly($student, $payloadsMany));

        self::assertSame(
            $baseline,
            $afterGrowth,
            "N+1 détecté (étudiant) : {$baseline} requêtes à 3 séances vs {$afterGrowth} à 30 séances.",
        );
        // Borne dure : 1 SELECT seances + 1 SELECT seance_user_hidden, mutualisés.
        self::assertSame(2, $baseline, 'Le chemin étudiant doit émettre exactement 2 requêtes locales.');
    }

    public function test_teacher_path_query_count_is_constant_as_seances_grow(): void
    {
        $teacher = $this->userWithRole('enseignant');

        $payloadsFew = $this->seedSeances(3, 10_000);
        $payloadsMany = $this->seedSeances(30, 20_000);

        $baseline = $this->countLocalSeancesQueries(fn () => $this->fetchOnly($teacher, $payloadsFew));
        $afterGrowth = $this->countLocalSeancesQueries(fn () => $this->fetchOnly($teacher, $payloadsMany));

        self::assertSame(
            $baseline,
            $afterGrowth,
            "N+1 détecté (enseignant) : {$baseline} requêtes à 3 séances vs {$afterGrowth} à 30 séances.",
        );
        // Enseignant : pas de filtrage masqué → seul enrichWithVisio touche `seances`.
        self::assertSame(1, $baseline, 'Le chemin enseignant doit émettre exactement 1 requête locale.');
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->for($this->institution)->create([
            'role' => $role,
            'klassci_id' => 555,
            'klassci_token' => 'fake-token',
        ]);
    }

    /**
     * Exécute fetch() avec un walk KLASSCI mocké retournant $count séances locales
     * réelles (une Seance en base par séance KLASSCI, pour que les lookups matchent).
     */
    /**
     * Setup HORS mesure : crée $count Seance locales réelles et retourne les
     * payloads KLASSCI correspondants (les INSERT ne doivent pas être comptés).
     *
     * @return list<array<string, mixed>>
     */
    private function seedSeances(int $count, int $idOffset): array
    {
        $payloads = [];
        for ($i = 1; $i <= $count; $i++) {
            $klassciSeanceId = $idOffset + $i;
            Seance::factory()->forInstitution($this->institution)->create([
                'klassci_seance_id' => $klassciSeanceId,
                'klassci_classe_id' => 200,
                'is_active' => true,
            ]);
            $payloads[] = $this->seancePayload($klassciSeanceId, 200, '2026-08-15');
        }

        return $payloads;
    }

    /**
     * Exécute UNIQUEMENT fetch() (le walk KLASSCI est mocké), sur des séances déjà
     * créées. C'est ce seul appel qui est mesuré par countLocalSeancesQueries.
     *
     * @param  list<array<string, mixed>>  $payloads
     */
    private function fetchOnly(User $user, array $payloads): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($payloads): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('fake-token', 'matieres', 'GET')
                ->andReturn(['data' => [['id' => 42, 'nom' => 'Maths']]]);
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->with([42], 'fake-token')
                ->andReturn([42 => ['data' => ['seances_programmees' => $payloads]]]);
        });

        app(UpcomingSeancesFetcher::class)
            ->fetch($user, 'fake-token', '2026-08-01', '2026-08-31', null, null);
    }

    /**
     * Compte les requêtes SQL touchant les tables locales `seances` /
     * `seance_user_hidden` pendant l'exécution de $run.
     */
    private function countLocalSeancesQueries(callable $run): int
    {
        DB::enableQueryLog();
        $run();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $queries->filter(fn (string $q): bool =>
            str_contains($q, 'seances') || str_contains($q, 'seance_user_hidden')
        )->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function seancePayload(int $id, int $classeId, string $date): array
    {
        return [
            'id' => $id,
            'classe' => ['id' => $classeId, 'nom' => 'Classe '.$classeId],
            'programmation' => [
                'date' => $date,
                'heure_debut' => $date.'T09:00:00.000000Z',
                'heure_fin' => $date.'T10:00:00.000000Z',
                'salle' => 'Salle 1',
            ],
        ];
    }
}
