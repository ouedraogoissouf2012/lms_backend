<?php

declare(strict_types=1);

namespace App\Services\Klassci;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

final class KlassciCircuitBreaker
{
    private const FAILURES_KEY = 'klassci:circuit:failures';
    private const OPEN_UNTIL_KEY = 'klassci:circuit:open_until';

    public function __construct(private readonly CacheRepository $cache)
    {
    }

    public function isOpen(): bool
    {
        $openUntil = $this->intFromCache(self::OPEN_UNTIL_KEY);

        return $openUntil !== null && $openUntil > time();
    }

    public function secondsUntilRetry(): int
    {
        $openUntil = $this->intFromCache(self::OPEN_UNTIL_KEY);

        if ($openUntil === null) {
            return 0;
        }

        return max(0, $openUntil - time());
    }

    public function reportSuccess(): void
    {
        $this->cache->forget(self::FAILURES_KEY);
        $this->cache->forget(self::OPEN_UNTIL_KEY);
    }

    public function reportFailure(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $failures = ($this->intFromCache(self::FAILURES_KEY) ?? 0) + 1;
        $this->cache->put(self::FAILURES_KEY, $failures, $this->failureWindowSeconds());

        if ($failures >= $this->failureThreshold()) {
            $this->cache->put(
                self::OPEN_UNTIL_KEY,
                Carbon::now()->addSeconds($this->cooldownSeconds())->timestamp,
                $this->cooldownSeconds()
            );
        }
    }

    private function enabled(): bool
    {
        return (bool) config('services.klassci.circuit_breaker_enabled', true);
    }

    private function failureThreshold(): int
    {
        return self::positiveIntConfig('services.klassci.circuit_breaker_failures', 3);
    }

    private function cooldownSeconds(): int
    {
        return self::positiveIntConfig('services.klassci.circuit_breaker_cooldown', 30);
    }

    private function failureWindowSeconds(): int
    {
        return self::positiveIntConfig('services.klassci.circuit_breaker_window', 60);
    }

    private static function positiveIntConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    private function intFromCache(string $key): ?int
    {
        $value = $this->cache->get($key);

        return is_numeric($value) ? (int) $value : null;
    }
}
