<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
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
        $this->assertStringContainsString('queue:work', (string) $event->command);
        $this->assertStringContainsString('--stop-when-empty', (string) $event->command);
        $this->assertStringContainsString('--max-time=', (string) $event->command);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        // runInBackground : le drain de la queue ne doit pas bloquer le tick
        // du scheduler (les 11 autres tâches passent dans le même schedule:run).
        $this->assertTrue($event->runInBackground);
    }
}
