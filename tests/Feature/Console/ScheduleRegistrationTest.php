<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\ArchiveOldSeances;
use App\Jobs\AutoCloseEmptySeances;
use App\Jobs\CleanOldEvaluations;
use App\Jobs\DetectDisconnectedParticipants;
use App\Jobs\FinalizeSeanceAttendances;
use App\Jobs\SyncKlassciSeances;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Issue #369 — Garde-fou sur le câblage de `routes/console.php`.
 *
 * L'origine des incidents historiques (visios jamais fermées, présences
 * jamais finalisées) est un job existant mais NON planifié
 * (`AutoCloseEmptySeances`). Ce test fige le contrat : les tâches
 * critiques pour la prod cPanel DOIVENT être enregistrées, avec la bonne
 * fréquence et les bons verrous.
 *
 * @see routes/console.php
 * @see docs/DEPLOYMENT_OPS.md
 */
final class ScheduleRegistrationTest extends TestCase
{
    /**
     * Événements planifiés, indexés par leur nom (`->name(...)`).
     *
     * @return Collection<string, Event>
     */
    private function scheduledEvents(): Collection
    {
        return (new Collection($this->app->make(Schedule::class)->events()))
            ->keyBy(fn (Event $event): string => (string) $event->description);
    }

    public function test_auto_close_empty_seances_runs_every_five_minutes_with_locks(): void
    {
        $event = $this->scheduledEvents()->get('auto-close-empty-seances');

        $this->assertNotNull($event, 'AutoCloseEmptySeances doit être planifié (racine issue #369).');
        // 5 min = plus petit seuil des règles de fermeture
        // (TeacherDisconnectionRule::TEACHER_DISCONNECT_THRESHOLD).
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_expire_quiz_attempts_runs_every_five_minutes_with_locks(): void
    {
        $event = $this->scheduledEvents()->get('expire-quiz-attempts');

        $this->assertNotNull($event, 'quiz:expire-attempts doit être planifié (#502) — filet de sécurité des tentatives de quiz abandonnées.');
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_scheduler_heartbeat_runs_every_minute(): void
    {
        $event = $this->scheduledEvents()->get('scheduler-heartbeat');

        $this->assertNotNull($event, 'Le heartbeat est le marqueur de vie lu par scheduler:healthcheck.');
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_queue_worker_drains_jobs_every_minute_without_overlapping(): void
    {
        $event = $this->scheduledEvents()->get('queue-worker');

        $this->assertNotNull($event, 'Sans worker planifié, les jobs s\'accumulent dans la table jobs (cPanel sans Supervisor).');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertStringContainsString('queue:drain', (string) $event->command);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        // runInBackground : le drain de la queue ne doit pas bloquer le tick
        // du scheduler (les 13 autres tâches passent dans le même schedule:run).
        $this->assertTrue($event->runInBackground);
    }

    public function test_queue_healthcheck_runs_every_five_minutes(): void
    {
        $event = $this->scheduledEvents()->get('queue-healthcheck');

        $this->assertNotNull($event, 'La queue database doit être surveillée sur cPanel sans Redis.');
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertStringContainsString('queue:healthcheck', (string) $event->command);
        $this->assertStringContainsString('--max-pending=1000', (string) $event->command);
        $this->assertStringContainsString('--max-age-minutes=5', (string) $event->command);
        $this->assertStringContainsString('--max-failed=0', (string) $event->command);
        $this->assertStringContainsString('--max-worker-heartbeat-minutes=5', (string) $event->command);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_recording_retention_runs_daily_with_explicit_apply(): void
    {
        $event = $this->scheduledEvents()->get('purge-visio-recordings');

        $this->assertNotNull($event);
        $this->assertSame('45 3 * * *', $event->expression);
        $this->assertStringContainsString('recordings:purge --apply', (string) $event->command);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    /**
     * #674 — Une commande de purge qui existe sans être planifiée ne purge rien,
     * et c'est exactement l'incident historique que ce fichier fige.
     *
     * L'heure n'est pas décorative : `chapters:purge` doit passer APRÈS
     * `recordings:purge`. Un chapitre engendré par un enregistrement appartient
     * à la rétention visio tant qu'une ligne `seance_recordings` le référence ;
     * l'inverser ferait attendre un cycle entier aux chapitres dont
     * l'enregistrement vient d'expirer.
     */
    public function test_chapter_retention_runs_daily_after_recording_retention(): void
    {
        $event = $this->scheduledEvents()->get('purge-trashed-chapters');

        $this->assertNotNull($event, 'chapters:purge doit être planifié (#674) — sinon la corbeille ne se vide jamais.');
        $this->assertSame('50 3 * * *', $event->expression);
        $this->assertStringContainsString('chapters:purge --apply', (string) $event->command);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);

        $recordings = $this->scheduledEvents()->get('purge-visio-recordings');
        $this->assertNotNull($recordings);
        $this->assertTrue(
            $this->minuteOfDay($recordings->expression) < $this->minuteOfDay($event->expression),
            'La rétention visio doit passer AVANT la purge des chapitres.',
        );
    }

    /** Convertit la partie horaire d'une expression cron en minutes depuis minuit. */
    private function minuteOfDay(string $expression): int
    {
        [$minute, $hour] = explode(' ', $expression);

        return ((int) $hour * 60) + (int) $minute;
    }

    /**
     * Le filet de sécurité #514 / #680 était planifié sans être verrouillé par
     * un test : renommer la commande cassait le planificateur en silence, la
     * suite restant verte. Ce test ferme ce trou.
     *
     * Sa valeur réelle : sans lui, plus rien ne referme les enregistrements
     * bloqués, donc `active_lock_key` n'est jamais rendu et aucune séance
     * concernée ne peut être ré-enregistrée.
     */
    public function test_stale_recordings_sweeper_is_scheduled(): void
    {
        $event = $this->scheduledEvents()->get('fail-stale-recordings');

        $this->assertNotNull($event, 'le balayage des enregistrements bloqués n\'est plus planifié');
        $this->assertSame('*/30 * * * *', $event->expression);
        $this->assertStringContainsString('recordings:fail-stale', (string) $event->command);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_scheduled_jobs_dispatch_to_priority_queues(): void
    {
        config(['cache.default' => 'array']);
        Bus::fake();

        $this->runScheduledJob('sync-klassci-seances');
        $this->runScheduledJob('archive-old-seances');
        $this->runScheduledJob('clean-old-evaluations');
        $this->runScheduledJob('detect-disconnected-participants');
        $this->runScheduledJob('finalize-seance-attendances');
        $this->runScheduledJob('auto-close-empty-seances');

        Bus::assertDispatched(SyncKlassciSeances::class, fn ($job): bool => $job->queue === 'low');
        Bus::assertDispatched(ArchiveOldSeances::class, fn ($job): bool => $job->queue === 'low');
        Bus::assertDispatched(CleanOldEvaluations::class, fn ($job): bool => $job->queue === 'low');
        Bus::assertDispatched(DetectDisconnectedParticipants::class, fn ($job): bool => $job->queue === 'high');
        Bus::assertDispatched(FinalizeSeanceAttendances::class, fn ($job): bool => $job->queue === 'low');
        Bus::assertDispatched(AutoCloseEmptySeances::class, fn ($job): bool => $job->queue === 'high');
    }

    private function runScheduledJob(string $name): void
    {
        $event = $this->scheduledEvents()->get($name);

        $this->assertInstanceOf(CallbackEvent::class, $event);
        $event->run($this->app);
    }
}
