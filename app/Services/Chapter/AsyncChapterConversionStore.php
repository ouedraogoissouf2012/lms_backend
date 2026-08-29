<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

final class AsyncChapterConversionStore
{
    private const TTL_SECONDS = 86400;

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function pending(string $id, int $chapterId, int $userId, array $meta): void
    {
        $this->put($id, [
            'id' => $id,
            'chapter_id' => $chapterId,
            'user_id' => $userId,
            'status' => 'pending',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'meta' => $meta,
        ]);
    }

    public function running(string $id): void
    {
        $this->patch($id, ['status' => 'running']);
    }

    public function completed(string $id, int $chapterId): void
    {
        $this->patch($id, [
            'status' => 'completed',
            'chapter_id' => $chapterId,
        ]);
    }

    public function failed(string $id, string $message): void
    {
        $this->patch($id, [
            'status' => 'failed',
            'message' => $message,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        $value = $this->cache->get($this->key($id));
        if (! is_array($value)) {
            return null;
        }

        $payload = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $payload[$key] = $item;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function patch(string $id, array $patch): void
    {
        $current = $this->get($id);
        if ($current === null) {
            return;
        }

        $this->put($id, array_merge($current, $patch, [
            'updated_at' => now()->toIso8601String(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function put(string $id, array $payload): void
    {
        $this->cache->put($this->key($id), $payload, self::TTL_SECONDS);
    }

    private function key(string $id): string
    {
        return 'async_chapter_conversion_'.$id;
    }
}
