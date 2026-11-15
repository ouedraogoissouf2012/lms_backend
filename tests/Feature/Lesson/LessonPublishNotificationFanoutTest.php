<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Jobs\DispatchLessonPublishedNotifications;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\User;
use App\Services\Lesson\LessonCrudOperationsService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Issue #538 (P1/PERF) — Fan-out des notifications de publication déplacé en job
 * ASYNCHRONE, avec une insertion groupée.
 *
 * Deux propriétés vérifiées :
 *  1. `publish()` ne fait pas le travail lourd en synchrone : il dispatche
 *     {@see DispatchLessonPublishedNotifications}.
 *  2. Le job repose le tenant de la leçon, n'avertit que les élèves de SA classe et de
 *     SON établissement, puis rend le worker sans tenant.
 *
 * Migration déclarée ((b), D614) : le second test simulait KLASSCI (`classe_ids`,
 * `etudiant_ids`), une forme absente des réponses réelles ; il était vert sans rien
 * prouver. Son intention, « seuls les élèves du bon établissement », est gardée sur la
 * vraie règle (les élèves inscrits dans la classe de la leçon, `EnrollmentSource`).
 * Le détail par école est dans {@see ElevesAvertisDeLaPublicationTest}.
 *
 * @see app/Jobs/DispatchLessonPublishedNotifications.php
 * @see app/Services/Lesson/LessonCrudOperationsService.php
 */
final class LessonPublishNotificationFanoutTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Ne pas fuiter un tenant résiduel vers le test suivant.
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_publish_dispatches_fanout_job_instead_of_running_synchronously(): void
    {
        Queue::fake();

        $inst = Institution::factory()->create();
        app(TenantManager::class)->set($inst);

        $lesson = Lesson::factory()->create([
            'institution_id' => $inst->id,
            'classe_id' => Classe::factory()->create(['institution_id' => $inst->id])->id,
            'matiere_id' => 500,
            'title' => 'Introduction à Laravel',
            'status' => 'draft',
            'published_at' => null,
        ]);

        app(LessonCrudOperationsService::class)->publish($lesson);

        Queue::assertPushed(
            DispatchLessonPublishedNotifications::class,
            function (DispatchLessonPublishedNotifications $job) use ($lesson, $inst): bool {
                return $job->lessonId === (int) $lesson->id
                    && $job->matiereId === 500
                    && $job->lessonTitle === 'Introduction à Laravel'
                    && $job->institutionId === (int) $inst->id;
            },
        );
    }

    public function test_job_notifies_only_the_students_of_the_lesson_school_and_releases_the_tenant(): void
    {
        Http::preventStrayRequests();
        $instA = Institution::factory()->standalone()->create();
        $instB = Institution::factory()->standalone()->create();
        $classe = Classe::factory()->create(['institution_id' => $instA->id]);

        $userA1 = User::factory()->create(['institution_id' => $instA->id, 'role' => 'etudiant']);
        $userA2 = User::factory()->create(['institution_id' => $instA->id, 'role' => 'etudiant']);
        // Une ligne de pivot anormale vers la classe de A, depuis un compte de B.
        $userB = User::factory()->create(['institution_id' => $instB->id, 'role' => 'etudiant']);
        foreach ([$userA1, $userA2, $userB] as $eleve) {
            $classe->etudiants()->attach($eleve->id, ['statut' => 'actif']);
        }
        $lesson = Lesson::factory()->create([
            'institution_id' => $instA->id,
            'classe_id' => $classe->id,
            'matiere_id' => 500,
            'title' => 'Introduction à Laravel',
        ]);

        // Worker sans tenant au départ : le job doit reposer le tenant lui-même.
        app(TenantManager::class)->reset();

        $job = new DispatchLessonPublishedNotifications(
            lessonId: (int) $lesson->id,
            matiereId: 500,
            lessonTitle: 'Introduction à Laravel',
            institutionId: (int) $instA->id,
        );
        $this->app->call([$job, 'handle']);

        // Requête hors scope pour observer TOUTES les institutions.
        $notifs = Notification::withoutGlobalScope('institution')->get();

        $this->assertEqualsCanonicalizing(
            [(int) $userA1->id, (int) $userA2->id],
            $notifs->pluck('user_id')->map(static fn ($id): int => (int) $id)->all(),
            'Seuls les élèves de A, inscrits dans la classe de la leçon, sont avertis.',
        );
        $this->assertFalse($notifs->contains('user_id', (int) $userB->id), 'le compte de B n\'est pas averti');

        // institution_id + contenu corrects.
        $first = $notifs->firstWhere('user_id', (int) $userA1->id);
        $this->assertNotNull($first);
        $this->assertSame((int) $instA->id, (int) $first->institution_id);
        $this->assertSame(Notification::TYPE_LESSON_PUBLISHED, $first->type);
        $this->assertStringContainsString('Introduction à Laravel', (string) $first->message);
        $this->assertSame((int) $lesson->id, (int) ($first->data['lesson_id'] ?? null));
        $this->assertSame(500, (int) ($first->data['matiere_id'] ?? null));

        // Le job ne fuit pas le tenant vers le job suivant du worker.
        $this->assertNull(app(TenantManager::class)->id());
    }
}
