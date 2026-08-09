<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Jobs\DispatchLessonPublishedNotifications;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Lesson\LessonCrudOperationsService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Issue #538 (P1/PERF) — Fan-out des notifications de publication déplacé en job
 * ASYNCHRONE + parallélisé (Http::pool) + `whereIn`.
 *
 * Deux propriétés vérifiées :
 *  1. `publish()` ne fait plus le travail lourd en synchrone : il dispatche
 *     {@see DispatchLessonPublishedNotifications} (plus de 1+N GET HTTP en requête).
 *  2. Le job repose le tenant du demandeur puis n'insère des notifications que
 *     pour les étudiants du BON tenant (pas de fuite cross-tenant sur collision
 *     de `klassci_id`), via UN batch KLASSCI + UNE requête `whereIn`.
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

    public function test_job_notifies_only_current_tenant_students_via_wherein(): void
    {
        $instA = Institution::factory()->create();
        $instB = Institution::factory()->create();

        // Deux étudiants dans A, deux dans B — dont une COLLISION de klassci_id (1002)
        // entre A et B pour prouver le scope tenant du fan-out.
        $userA1 = User::factory()->create(['institution_id' => $instA->id, 'klassci_id' => 1001]);
        $userA2 = User::factory()->create(['institution_id' => $instA->id, 'klassci_id' => 1002]);
        $userB2 = User::factory()->create(['institution_id' => $instB->id, 'klassci_id' => 1002]);
        $userB3 = User::factory()->create(['institution_id' => $instB->id, 'klassci_id' => 1003]);

        // KLASSCI mocké : matière 500 → classes [10, 11] → étudiants [1001,1002,1003].
        // UN GET matière + UN batch parallèle (pas N GET séquentiels bloquants).
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('get')
                ->once()
                ->with('/matieres/500')
                ->andReturn(['data' => ['classe_ids' => [10, 11]]]);
            $mock->shouldReceive('fetchManyByEndpoint')
                ->once()
                ->andReturn([
                    10 => ['data' => ['etudiant_ids' => [1001, 1002]]],
                    11 => ['data' => ['etudiant_ids' => [1002, 1003]]],
                ]);
        });

        // Worker tenant-less au départ : le job doit reposer le tenant lui-même.
        app(TenantManager::class)->reset();

        $job = new DispatchLessonPublishedNotifications(
            lessonId: 42,
            matiereId: 500,
            lessonTitle: 'Introduction à Laravel',
            institutionId: (int) $instA->id,
        );
        $this->app->call([$job, 'handle']);

        // Requête hors scope pour observer TOUTES les institutions.
        $notifs = Notification::withoutGlobalScope('institution')->get();

        $this->assertCount(2, $notifs, 'Seuls les 2 étudiants du tenant A doivent être notifiés.');
        $this->assertEqualsCanonicalizing(
            [(int) $userA1->id, (int) $userA2->id],
            $notifs->pluck('user_id')->map(static fn ($id): int => (int) $id)->all(),
            'Les notifications doivent viser userA1 + userA2 (whereIn), pas les users de B.',
        );

        // Pas de fuite cross-tenant : userB2 partage le klassci_id 1002 mais NE DOIT PAS
        // être notifié (mauvais tenant) ; userB3 (1003) non plus.
        $this->assertFalse(
            $notifs->contains('user_id', (int) $userB2->id),
            'Collision klassci_id 1002 : le user de B ne doit pas être notifié.',
        );
        $this->assertFalse($notifs->contains('user_id', (int) $userB3->id));

        // institution_id + contenu corrects.
        $first = $notifs->firstWhere('user_id', (int) $userA1->id);
        $this->assertNotNull($first);
        $this->assertSame((int) $instA->id, (int) $first->institution_id);
        $this->assertSame(Notification::TYPE_LESSON_PUBLISHED, $first->type);
        $this->assertStringContainsString('Introduction à Laravel', (string) $first->message);
        $this->assertSame(42, (int) ($first->data['lesson_id'] ?? null));
        $this->assertSame(500, (int) ($first->data['matiere_id'] ?? null));

        // Le job ne fuit pas le tenant vers le job suivant du worker.
        $this->assertNull(app(TenantManager::class)->id());
    }
}
