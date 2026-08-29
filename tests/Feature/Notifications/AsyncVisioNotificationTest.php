<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\DispatchVisioNotification;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\AsyncVisioNotificationDispatcher;
use App\Services\Notification\VisioNotificationIdempotencyGuard;
use App\Services\NotificationService;
use App\Services\TenantManager;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

final class AsyncVisioNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        $this->disableKlassciMiddleware();
    }

    public function test_scheduled_visio_notification_is_queued_on_default(): void
    {
        Queue::fake();

        app(AsyncVisioNotificationDispatcher::class)->queueScheduled(42, [
            'klassci_classe_id' => 10,
            'matiere_nom' => 'Math',
        ]);

        Queue::assertPushedOn('default', DispatchVisioNotification::class);
    }

    public function test_starting_visio_notification_is_queued_on_high(): void
    {
        Queue::fake();

        app(AsyncVisioNotificationDispatcher::class)->queueStarting(42, [
            'klassci_classe_id' => 10,
            'matiere_nom' => 'Math',
        ]);

        Queue::assertPushedOn('high', DispatchVisioNotification::class);
    }

    public function test_job_skips_when_visio_notification_already_exists(): void
    {
        $user = User::factory()->student()->create();

        Notification::factory()->create([
            'user_id' => $user->id,
            'type' => Notification::TYPE_VISIO_STARTING,
            'data' => ['seance_id' => 42],
        ]);

        (new DispatchVisioNotification(DispatchVisioNotification::STARTING, 42, []))->handle(
            app(NotificationService::class),
            app(LoggerInterface::class),
        );

        self::assertSame(1, Notification::query()->count());
    }

    public function test_replayed_jobs_create_one_logical_notification(): void
    {
        $this->enrolledStudent();
        $job = new DispatchVisioNotification(DispatchVisioNotification::STARTING, 42, [
            'klassci_classe_id' => 10,
        ]);

        $job->handle(app(NotificationService::class), app(LoggerInterface::class));
        $job->handle(app(NotificationService::class), app(LoggerInterface::class));

        self::assertSame(1, Notification::query()->count());
    }

    public function test_contended_atomic_lock_defers_without_sending(): void
    {
        $this->enrolledStudent();
        $store = app(CacheFactory::class)->store()->getStore();
        self::assertInstanceOf(LockProvider::class, $store);

        $lock = $store->lock(
            VisioNotificationIdempotencyGuard::key(Notification::TYPE_VISIO_STARTING, 42),
            30,
        );
        self::assertTrue($lock->get());

        try {
            (new DispatchVisioNotification(DispatchVisioNotification::STARTING, 42, [
                'klassci_classe_id' => 10,
            ]))->handle(app(NotificationService::class), app(LoggerInterface::class));
            self::fail('A contended dispatch must be retried.');
        } catch (RuntimeException $exception) {
            self::assertSame('Visio notification dispatch is already locked.', $exception->getMessage());
            self::assertSame(0, Notification::query()->count());
        } finally {
            $lock->release();
        }
    }

    private function enrolledStudent(): User
    {
        $institution = Institution::factory()->create();
        app(TenantManager::class)->set($institution);
        $classe = Classe::factory()->for($institution)->create(['klassci_id' => 10]);
        $student = User::factory()->student()->for($institution)->create();
        $classe->etudiants()->attach($student->id, ['statut' => 'actif']);

        return $student;
    }
}
