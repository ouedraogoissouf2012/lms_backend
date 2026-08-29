<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class VisioNotificationIdempotencyGuard
{
    private const LOCK_TTL_SECONDS = 150;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly DatabaseManager $database,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param Closure(): int $dispatch */
    public function run(string $type, int $seanceId, Closure $dispatch): int
    {
        $store = $this->cache->store()->getStore();
        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The configured cache store does not support atomic locks.');
        }

        $result = $store->lock(self::key($type, $seanceId), self::LOCK_TTL_SECONDS)
            ->get(function () use ($type, $seanceId, $dispatch): int {
                return $this->database->transaction(function () use ($type, $seanceId, $dispatch): int {
                    if ($this->alreadyDispatched($type, $seanceId)) {
                        $this->logger->info('Async visio notification skipped', [
                            'type' => $type,
                            'seance_id' => $seanceId,
                            'reason' => 'already_dispatched',
                        ]);

                        return 0;
                    }

                    return $dispatch();
                });
            });

        if ($result === false) {
            throw new RuntimeException('Visio notification dispatch is already locked.');
        }

        if (! is_int($result)) {
            throw new RuntimeException('Visio notification dispatch returned an invalid result.');
        }

        return $result;
    }

    public static function key(string $type, int $seanceId): string
    {
        return "visio-notification:{$type}:seance:{$seanceId}";
    }

    private function alreadyDispatched(string $type, int $seanceId): bool
    {
        return Notification::query()
            ->where('type', $type)
            ->whereRaw("json_extract(data, '$.seance_id') = ?", [$seanceId])
            ->where('created_at', '>', now()->subDay())
            ->exists();
    }
}
