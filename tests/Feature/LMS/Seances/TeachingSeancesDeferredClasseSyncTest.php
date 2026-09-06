<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Jobs\SyncKlassciClasse;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\TeachingSeancesFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * La synchronisation d'une classe ne doit plus se faire pendant que l'enseignant
 * attend sa liste de séances.
 *
 * ## Le défaut corrigé
 *
 * `TeachingSeancesFetcher::ensureLocalSeanceExists()` appelait
 * `ClasseSyncService::syncClasseById()` — donc `GET classes/{id}` — **dans la
 * boucle sur les séances**, en synchrone. Son propre commentaire disait pourquoi :
 * « Synchroniser la classe pour les notifications futures ». Un travail destiné à
 * un besoin FUTUR, exécuté sur le chemin critique d'un utilisateur présent.
 *
 * Trois conséquences mesurées en production le 2026-09-04 :
 *
 * - une troisième vague d'appels, séquentielle, alors que `fetchManyClassesDetails`
 *   avait DÉJÀ récupéré ces mêmes classes en un pool parallèle ;
 * - `classes/103`, `104`, `105`, `106` dans la même seconde — exactement la
 *   signature de rafale qui arme le filtre anti-abus de l'hébergeur de KLASSCI ;
 * - un enseignant dont la liste de séances échoue entièrement parce qu'une
 *   synchronisation accessoire n'a pas abouti.
 *
 * ## Ce que ce fichier verrouille
 *
 * Le travail part en file d'attente. L'enseignant obtient ses séances sans
 * attendre, et la synchronisation garde toute la latitude d'un worker : réessais,
 * temporisation, exécution différée quand KLASSCI redevient joignable.
 *
 * Le jeton KLASSCI n'est **pas** placé dans la charge utile du job — il serait
 * sérialisé en clair dans la table `jobs`. Le job le relit depuis l'utilisateur.
 *
 * @see TeachingSeancesFetcher
 * @see SyncKlassciClasse
 */
final class TeachingSeancesDeferredClasseSyncTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'klassci-user-token';

    private Institution $institution;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
            'klassci_id' => 777,
            'klassci_token' => self::TOKEN,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * LE test du défaut : plus aucun appel réseau `classes/{id}` pendant que
     * l'enseignant attend.
     *
     * L'assertion porte sur la **frontière réseau**, pas sur le service appelé :
     * c'est l'appel HTTP qui coûte à l'utilisateur et qui arme le filtre, et il
     * resterait fautif même si on le déplaçait dans un autre service.
     */
    public function test_no_classe_http_call_happens_while_the_teacher_waits(): void
    {
        Queue::fake();

        $this->fetcherWithKlassci(function (Mockery\MockInterface $proxy): void {
            $proxy->shouldNotReceive('requestWithUserToken')
                ->with(self::TOKEN, 'classes/101', 'GET');
        })->fetch($this->teacher, self::TOKEN);

        Queue::assertPushed(SyncKlassciClasse::class);
    }

    /**
     * Le travail n'est pas perdu : il est reporté, avec l'identifiant de classe et
     * l'utilisateur qui permettront au worker de le reprendre.
     */
    public function test_the_deferred_job_carries_what_the_worker_needs(): void
    {
        Queue::fake();

        $this->fetcherWithKlassci()->fetch($this->teacher, self::TOKEN);

        Queue::assertPushed(
            SyncKlassciClasse::class,
            fn (SyncKlassciClasse $job): bool => $job->klassciClasseId === 101
                && $job->userId === $this->teacher->id
                && $job->institutionId === $this->institution->id,
        );
    }

    /**
     * Le jeton KLASSCI ne doit JAMAIS voyager dans la charge utile : les jobs sont
     * sérialisés dans la table `jobs`, donc il y serait lisible en clair.
     */
    public function test_the_klassci_token_never_travels_in_the_job_payload(): void
    {
        Queue::fake();

        $this->fetcherWithKlassci()->fetch($this->teacher, self::TOKEN);

        Queue::assertPushed(SyncKlassciClasse::class, function (SyncKlassciClasse $job): bool {
            self::assertStringNotContainsString(
                self::TOKEN,
                (string) json_encode(get_object_vars($job)),
                'Le jeton KLASSCI est sérialisé dans la charge utile du job.',
            );

            return true;
        });
    }

    /**
     * La séance est créée immédiatement : la réponse ne dépend plus du tout de la
     * synchronisation de la classe.
     */
    public function test_the_seance_is_created_without_waiting_for_the_sync(): void
    {
        Queue::fake();

        $this->fetcherWithKlassci()->fetch($this->teacher, self::TOKEN);

        $this->assertDatabaseHas('seances', [
            'klassci_seance_id' => 5001,
            'institution_id' => $this->institution->id,
        ]);
    }

    /**
     * Le job est poussé sur la file `low` : c'est un travail d'arrière-plan
     * accessoire, il ne doit pas passer devant les notifications visio.
     */
    public function test_the_job_lands_on_the_low_priority_queue(): void
    {
        Queue::fake();

        $this->fetcherWithKlassci()->fetch($this->teacher, self::TOKEN);

        Queue::assertPushedOn('low', SyncKlassciClasse::class);
    }

    /**
     * Un enseignant sans séance nouvelle ne doit générer aucun travail différé.
     */
    public function test_an_already_local_seance_defers_nothing(): void
    {
        Queue::fake();

        Seance::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_seance_id' => 5001,
            'klassci_classe_id' => 101,
        ]);

        $this->fetcherWithKlassci()->fetch($this->teacher, self::TOKEN);

        Queue::assertNotPushed(SyncKlassciClasse::class);
    }

    // ───────────────────── Fixtures ─────────────────────

    /**
     * @param  (callable(Mockery\MockInterface): void)|null  $extraExpectations
     */
    private function fetcherWithKlassci(?callable $extraExpectations = null): TeachingSeancesFetcher
    {
        $proxy = Mockery::mock(KlassciProxyService::class);

        if ($extraExpectations !== null) {
            $extraExpectations($proxy);
        }

        $proxy->shouldReceive('requestWithUserToken')
            ->with(self::TOKEN, 'me/teacher-dashboard', 'GET')
            ->andReturn(['data' => ['matieres' => [['id' => 11, 'nom' => 'Maths']]]]);

        $proxy->shouldReceive('fetchManyMatieresDetails')
            ->andReturn([
                11 => ['data' => ['seances_programmees' => [[
                    'id' => 5001,
                    'classe' => ['id' => 101, 'nom' => 'Classe 101'],
                    'programmation' => [
                        'date' => '2026-06-26',
                        'heure_debut' => '2026-06-25T08:00:00.000000Z',
                        'heure_fin' => '2026-06-25T10:00:00.000000Z',
                        'salle' => 'Salle 1',
                    ],
                ]]]],
            ]);

        $proxy->shouldReceive('fetchManyClassesDetails')
            ->andReturn([101 => ['data' => ['classe' => ['places_occupees' => 30]]]]);

        $this->app->instance(KlassciProxyService::class, $proxy);

        return $this->app->make(TeachingSeancesFetcher::class);
    }
}
