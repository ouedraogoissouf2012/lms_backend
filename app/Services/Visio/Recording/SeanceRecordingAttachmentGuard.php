<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Models\Chapter;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final class SeanceRecordingAttachmentGuard
{
    private const LOCK_TTL_SECONDS = 150;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly DatabaseManager $database,
    ) {}

    /** @param Closure(): Chapter $attach */
    public function run(int $seanceId, Closure $attach): Chapter
    {
        $store = $this->cache->store()->getStore();
        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The configured cache store does not support atomic locks.');
        }

        $result = $store->lock(self::key($seanceId), self::LOCK_TTL_SECONDS)
            ->get(fn (): Chapter => $this->database->transaction($attach));

        if ($result === false) {
            throw new RuntimeException('Seance recording attachment is already locked.');
        }

        if (! $result instanceof Chapter) {
            throw new RuntimeException('Seance recording attachment returned an invalid result.');
        }

        return $result;
    }

    public static function key(int $seanceId): string
    {
        return "visio-recording:chapter:seance:{$seanceId}";
    }
}
