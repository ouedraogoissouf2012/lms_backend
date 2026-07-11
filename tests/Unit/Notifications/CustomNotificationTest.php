<?php

namespace Tests\Unit\Notifications;

use App\Notifications\CustomNotification;
use PHPUnit\Framework\TestCase;

final class CustomNotificationTest extends TestCase
{
    public function test_database_payload_contract_is_preserved(): void
    {
        $notification = new CustomNotification(
            title: 'Cours publié',
            message: 'Un nouveau cours est disponible.',
            type: 'success',
            actionUrl: '/courses/42',
        );

        $this->assertSame(['database'], $notification->via(new \stdClass));
        $this->assertSame(
            [
                'title' => 'Cours publié',
                'message' => 'Un nouveau cours est disponible.',
                'type' => 'success',
                'action_url' => '/courses/42',
                'icon' => 'CheckCircleIcon',
            ],
            $notification->toArray(new \stdClass),
        );
    }

    public function test_unknown_type_falls_back_to_bell_icon(): void
    {
        $notification = new CustomNotification(
            title: 'Rappel',
            message: 'Action requise.',
            type: 'custom',
        );

        $this->assertSame('BellIcon', $notification->toArray(new \stdClass)['icon']);
    }
}
