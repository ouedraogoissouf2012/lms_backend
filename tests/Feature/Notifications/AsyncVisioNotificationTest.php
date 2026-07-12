<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\DispatchVisioNotification;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\AsyncVisioNotificationDispatcher;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Psr\Log\LoggerInterface;
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
}
