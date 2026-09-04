<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\UpcomingSeancesFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * §1.4 PRODUCTION_STANDARDS.md — le chemin « séances à venir » ne doit demander
 * que les matières de l'UTILISATEUR, jamais le catalogue de l'établissement.
 *
 * Défaut mesuré en production locale le 04/09/2026 : `loadMatieresWithDetails()`
 * appelait `GET matieres`, qui renvoie le catalogue ENTIER (452 matières
 * mesurées sur le tenant `presentation`), alors que l'enseignant testé n'en
 * enseigne que 6. Le batch tentait donc de résoudre des centaines de matières
 * étrangères ; trace réelle (request_id eecf63b1) :
 *
 *     10:17:23  Récupération séances à venir
 *     ...       219 × "KLASSCI batch fetch failed"
 *     10:19:23  Maximum execution time of 120 seconds exceeded
 *
 * Deux minutes pour UNE fiche classe, et le commentaire du code annonçait
 * pourtant « Récupère les matières de l'utilisateur » — ce qui était faux.
 *
 * La source correcte existait déjà et est utilisée ailleurs dans ce dépôt :
 *   - enseignant → `me/teacher-dashboard` → `data.matieres`
 *     (précédent : MyMatieresQueryService, MatiereSeancesFetcher)
 *   - étudiant   → `me/dashboard` → `data.cours`
 *     (précédent : StudentClassesSeancesFetcher, dont le commentaire dit déjà
 *     « Utiliser les matières du dashboard au lieu de faire un nouvel appel »)
 *
 * Forme vérifiée en direct avant transposition : les matières du dashboard
 * portent bien `id`, `nom` et `code` — les trois seuls champs que consomme
 * `UpcomingSeanceMapper` (0 manquant sur 6).
 *
 * @see app/Services/Seances/UpcomingSeancesFetcher.php
 */
#[CoversClass(UpcomingSeancesFetcher::class)]
final class UpcomingSeancesScopedToUserMatieresTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'fake-token';

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

    private function userWithRole(string $role): User
    {
        return User::factory()->for($this->institution)->create([
            'role' => $role,
            'klassci_id' => 555,
            'klassci_token' => self::TOKEN,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function seancePayload(int $id): array
    {
        return [
            'id' => $id,
            'classe' => ['id' => 700, 'nom' => 'B2 COM'],
            'programmation' => [
                'date' => '2026-08-15',
                'heure_debut' => '2026-08-15T09:00:00.000000Z',
                'heure_fin' => '2026-08-15T10:00:00.000000Z',
            ],
        ];
    }

    public function test_teacher_path_asks_only_for_his_own_matieres_never_the_catalogue(): void
    {
        $teacher = $this->userWithRole('enseignant');

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            // Mock STRICT : `GET matieres` (le catalogue de 452) n'est PAS
            // configure. S'il est encore appele, Mockery fait echouer le test
            // au lieu de laisser passer un appel silencieux.
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with(self::TOKEN, 'me/teacher-dashboard', 'GET')
                ->andReturn(['data' => ['matieres' => [
                    ['id' => 1, 'nom' => 'Marketing digital', 'code' => 'ID2345'],
                    ['id' => 3, 'nom' => 'Anglais', 'code' => 'ID5356'],
                ]]]);

            // Le batch ne doit porter QUE les 2 matieres de l'enseignant.
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->once()
                ->with([1, 3], self::TOKEN)
                ->andReturn([
                    1 => ['data' => ['seances_programmees' => [$this->seancePayload(900_101)]]],
                    3 => ['data' => ['seances_programmees' => []]],
                ]);
        });

        $seances = app(UpcomingSeancesFetcher::class)
            ->fetch($teacher, self::TOKEN, '2026-08-01', '2026-08-31', null, null);

        self::assertCount(1, $seances);
        self::assertSame(900_101, $seances->first()['id']);
    }

    public function test_student_path_asks_only_for_his_own_cours_never_the_catalogue(): void
    {
        $student = $this->userWithRole('etudiant');

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with(self::TOKEN, 'me/dashboard', 'GET')
                ->andReturn(['data' => ['cours' => [
                    ['id' => 2, 'nom' => 'Algorithme', 'code' => 'ID456789'],
                ]]]);

            $mock->shouldReceive('fetchManyMatieresDetails')
                ->once()
                ->with([2], self::TOKEN)
                ->andReturn([
                    2 => ['data' => ['seances_programmees' => [$this->seancePayload(900_202)]]],
                ]);
        });

        $seances = app(UpcomingSeancesFetcher::class)
            ->fetch($student, self::TOKEN, '2026-08-01', '2026-08-31', null, null);

        self::assertCount(1, $seances);
        self::assertSame(900_202, $seances->first()['id']);
    }

    public function test_a_user_without_matieres_yields_no_seance_and_never_walks_the_catalogue(): void
    {
        // Cas limite : dashboard vide (enseignant sans affectation, ou reponse
        // degradee). Il ne doit PAS retomber sur le catalogue entier — c'est
        // exactement le repli qui produisait les 2 minutes de blocage.
        $teacher = $this->userWithRole('enseignant');

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with(self::TOKEN, 'me/teacher-dashboard', 'GET')
                ->andReturn(['data' => ['matieres' => []]]);

            $mock->shouldNotReceive('fetchManyMatieresDetails');
        });

        $seances = app(UpcomingSeancesFetcher::class)
            ->fetch($teacher, self::TOKEN, '2026-08-01', '2026-08-31', null, null);

        self::assertCount(0, $seances);
    }
}
