<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

final class DispatchVisioNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const SCHEDULED = 'scheduled';

    public const STARTING = 'starting';

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  array<string, mixed>  $seanceData
     */
    public function __construct(
        private readonly string $kind,
        private readonly int $seanceId,
        private readonly array $seanceData,
    ) {}

    public function handle(NotificationService $notifications, LoggerInterface $logger): void
    {
        try {
            $sent = $this->sendNotification($notifications);
            $logger->info('Async visio notification dispatched', [
                'kind' => $this->kind,
                'seance_id' => $this->seanceId,
                'notifications_sent' => $sent,
            ]);
        } catch (Throwable $e) {
            $logger->error('Async visio notification failed', [
                'kind' => $this->kind,
                'seance_id' => $this->seanceId,
                'exception' => $e::class,
            ]);

            throw $e;
        }
    }

    private function sendNotification(NotificationService $notifications): int
    {
        return match ($this->kind) {
            self::SCHEDULED => $notifications->notifyVisioScheduled($this->seanceId, $this->seanceData),
            self::STARTING => $notifications->notifyVisioStarting($this->seanceId, $this->seanceData),
            default => throw new InvalidArgumentException('Unsupported visio notification kind.'),
        };
    }
}
