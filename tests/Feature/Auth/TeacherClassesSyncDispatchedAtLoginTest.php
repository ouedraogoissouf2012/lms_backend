<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Jobs\SyncUserClasses;
use App\Models\Institution;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Le login enseignant doit DÉCLENCHER la synchronisation de ses classes (#712).
 *
 * ## Le maillon qui manquait
 *
 * `ClasseSyncService::syncUserClasses()` n'avait aucun appelant. Le lot #740 y
 * avait branché l'amorçage du miroir `classe_matiere` — sur une méthode que
 * personne n'appelle. Son test l'invoquait directement : il prouvait qu'elle
 * fonctionne, jamais qu'elle est atteinte.
 *
 * Résultat mesuré en production : `classe_matiere` à 0 ligne, « Mes Classes »
 * vide, correctif pourtant déployé et CI verte.
 *
 * C'est CE test qui manquait : il ne vérifie pas qu'une méthode marche, il
 * vérifie qu'un CHEMIN RÉEL la déclenche.
 */
final class TeacherClassesSyncDispatchedAtLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.klassci.url', 'https://klassci.test');
        config()->set('services.klassci.token', 'system-token');
        Http::fake(['*' => Http::response(['data' => []], 200)]);
    }

    /**
     * LE maillon : un login enseignant met la synchro en file.
     */
    public function test_a_teacher_login_dispatches_the_classes_sync(): void
    {
        Queue::fake();

        $institution = $this->login('enseignant');

        Queue::assertPushed(
            SyncUserClasses::class,
            static fn (SyncUserClasses $job): bool => $job->institutionId === (int) $institution->id,
        );
    }

    /**
     * Le coordinateur voit aussi des classes : même besoin, même déclenchement.
     */
    public function test_a_coordinator_login_dispatches_it_too(): void
    {
        Queue::fake();

        $this->login('coordinateur');

        Queue::assertPushed(SyncUserClasses::class);
    }

    /**
     * L'étudiant a son propre chemin (`StudentClassSynchronizer`) : il ne doit
     * pas déclencher la synchro enseignant, qui interroge `GET /classes`.
     */
    public function test_a_student_login_dispatches_nothing(): void
    {
        Queue::fake();

        $this->login('etudiant');

        Queue::assertNotPushed(SyncUserClasses::class);
    }

    /**
     * Le travail part sur la file `low` : il n'est jamais urgent et ne doit pas
     * passer devant les notifications visio.
     */
    public function test_the_sync_never_runs_on_the_request_path(): void
    {
        Queue::fake();

        $this->login('enseignant');

        Queue::assertPushed(
            SyncUserClasses::class,
            static fn (SyncUserClasses $job): bool => $job->queue === 'low',
        );
    }

    private function login(string $role): Institution
    {
        $institution = Institution::factory()->create(['slug' => 'school-a']);

        app(KlassciUserSynchronizer::class)->sync(
            ['id' => 9, 'nom' => 'PROF BEDE', 'email' => $role.'@school.edu', 'role' => $role],
            'teacher-token',
            'https://school-a.klassci.test',
            $institution,
        );

        return $institution;
    }
}
