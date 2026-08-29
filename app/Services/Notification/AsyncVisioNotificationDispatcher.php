<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Jobs\DispatchVisioNotification;
use Psr\Log\LoggerInterface;

final class AsyncVisioNotificationDispatcher
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $seanceData
     */
    public function queueScheduled(int $seanceId, array $seanceData): void
    {
        DispatchVisioNotification::dispatch(
            DispatchVisioNotification::SCHEDULED,
            $seanceId,
            $seanceData,
        )->onQueue('default');

        $this->logQueued(DispatchVisioNotification::SCHEDULED, $seanceId, 'default');
    }

    /**
     * @param  array<string, mixed>  $seanceData
     */
    public function queueStarting(int $seanceId, array $seanceData): void
    {
        DispatchVisioNotification::dispatch(
            DispatchVisioNotification::STARTING,
            $seanceId,
            $seanceData,
        )->onQueue('high');

        $this->logQueued(DispatchVisioNotification::STARTING, $seanceId, 'high');
    }

    private function logQueued(string $kind, int $seanceId, string $queue): void
    {
        $this->logger->info('Visio notification queued', [
            'kind' => $kind,
            'seance_id' => $seanceId,
            'queue' => $queue,
        ]);
    }
}
