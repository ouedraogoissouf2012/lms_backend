<?php

declare(strict_types=1);

namespace App\Services\AdminAnalytics;

/**
 * Exposes KLASSCI throttling/circuit-breaker settings for admin diagnostics.
 */
final class KlassciBackpressureMetricsService
{
    /**
     * @return array{
     *     timeout_seconds: int,
     *     connect_timeout_seconds: int,
     *     retry_after_seconds: int,
     *     circuit_breaker_enabled: bool,
     *     circuit_breaker_failure_threshold: int,
     *     circuit_breaker_cooldown_seconds: int,
     *     circuit_breaker_window_seconds: int
     * }
     */
    public function snapshot(): array
    {
        return [
            'timeout_seconds' => $this->configInt('services.klassci.timeout', 5),
            'connect_timeout_seconds' => $this->configInt('services.klassci.connect_timeout', 2),
            'retry_after_seconds' => $this->configInt('services.klassci.retry_after', 30),
            'circuit_breaker_enabled' => $this->configBool('services.klassci.circuit_breaker_enabled', true),
            'circuit_breaker_failure_threshold' => $this->configInt('services.klassci.circuit_breaker_failures', 3),
            'circuit_breaker_cooldown_seconds' => $this->configInt('services.klassci.circuit_breaker_cooldown', 30),
            'circuit_breaker_window_seconds' => $this->configInt('services.klassci.circuit_breaker_window', 60),
        ];
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private function configBool(string $key, bool $default): bool
    {
        $value = config($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return $default;
    }
}
