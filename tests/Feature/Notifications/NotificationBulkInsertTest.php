<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Institution;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationDispatcher;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #549 — sendToMany insère en bulk, avec institution_id.
 */
final class NotificationBulkInsertTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_to_many_inserts_one_row_per_user_with_tenant(): void
    {
        $institution = Institution::factory()->create();
        app(TenantManager::class)->set($institution);
        $users = User::factory()->for($institution)->count(3)->create();

        $count = app(NotificationDispatcher::class)->sendToMany(
            $users,
            Notification::TYPE_LESSON_PUBLISHED,
            'Nouveau cours',
            'Un cours a été publié',
            ['lesson_id' => 12],
        );

        self::assertSame(3, $count);
        self::assertSame(3, Notification::query()->count());
        self::assertSame(
            [$institution->id],
            Notification::query()->pluck('institution_id')->unique()->all()
        );
        self::assertSame(12, Notification::query()->first()?->data['lesson_id'] ?? null);
    }

    public function test_send_to_many_empty_collection_inserts_nothing(): void
    {
        $count = app(NotificationDispatcher::class)->sendToMany(
            collect(),
            Notification::TYPE_LESSON_PUBLISHED,
            'x',
            'y',
        );

        self::assertSame(0, $count);
        self::assertSame(0, Notification::query()->count());
    }
}
