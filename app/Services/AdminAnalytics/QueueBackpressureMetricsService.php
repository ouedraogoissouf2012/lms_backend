<?php

declare(strict_types=1);

namespace App\Services\AdminAnalytics;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * Local queue/backpressure signals for the admin dashboard.
 *
 * Reads only Laravel's database queue tables. No Redis, supervisor, or VPS
 * dependency: suitable for shared cPanel deployments.
 */
final class QueueBackpressureMetricsService
{
    public function __construct(
        private readonly ConnectionInterface $database,
    ) {
    }

    /**
     * @return array{
     *     available: bool,
     *     connection: string,
     *     pending: int,
     *     delayed: int,
     *     reserved: int,
     *     failed: int,
     *     oldest_pending_age_seconds: int|null,
     *     oldest_pending_age_minutes: float|null,
     *     queues: array<string, int>,
     *     unavailable_reason?: string
     * }
     */
    public function snapshot(): array
    {
        try {
            return $this->buildSnapshot();
        } catch (QueryException) {
            return $this->unavailableSnapshot('queue_tables_unavailable');
        }
    }

    /**
     * @return array{
     *     available: bool,
     *     connection: string,
     *     pending: int,
     *     delayed: int,
     *     reserved: int,
     *     failed: int,
     *     oldest_pending_age_seconds: int|null,
     *     oldest_pending_age_minutes: float|null,
     *     queues: array<string, int>
     * }
     */
    private function buildSnapshot(): array
    {
        $now = time();
        $jobs = $this->database->table('jobs');
        $pending = (clone $jobs)->whereNull('reserved_at')->where('available_at', '<=', $now);
        $oldestPendingCreatedAt = (clone $pending)->min('created_at');
        $oldestPendingAge = $this->ageInSeconds($oldestPendingCreatedAt, $now);

        return [
            'available' => true,
            'connection' => $this->queueConnection(),
            'pending' => (clone $pending)->count(),
            'delayed' => (clone $jobs)->whereNull('reserved_at')->where('available_at', '>', $now)->count(),
            'reserved' => (clone $jobs)->whereNotNull('reserved_at')->count(),
            'failed' => $this->database->table('failed_jobs')->count(),
            'oldest_pending_age_seconds' => $oldestPendingAge,
            'oldest_pending_age_minutes' => $oldestPendingAge === null ? null : round($oldestPendingAge / 60, 2),
            'queues' => $this->queueDepths($now),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function queueDepths(int $now): array
    {
        return $this->database->table('jobs')
            ->selectRaw('queue, COUNT(*) as pending_count')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now)
            ->groupBy('queue')
            ->pluck('pending_count', 'queue')
            ->map(fn (mixed $count): int => $this->intValue($count))
            ->all();
    }

    private function ageInSeconds(mixed $createdAt, int $now): ?int
    {
        if (!is_int($createdAt) && !(is_string($createdAt) && is_numeric($createdAt))) {
            return null;
        }

        return max(0, $now - (int) $createdAt);
    }

    private function intValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function queueConnection(): string
    {
        $connection = config('queue.default', 'sync');

        return is_string($connection) ? $connection : 'sync';
    }

    /**
     * @return array{
     *     available: bool,
     *     connection: string,
     *     pending: int,
     *     delayed: int,
     *     reserved: int,
     *     failed: int,
     *     oldest_pending_age_seconds: null,
     *     oldest_pending_age_minutes: null,
     *     queues: array<string, int>,
     *     unavailable_reason: string
     * }
     */
    private function unavailableSnapshot(string $reason): array
    {
        return [
            'available' => false,
            'connection' => $this->queueConnection(),
            'pending' => 0,
            'delayed' => 0,
            'reserved' => 0,
            'failed' => 0,
            'oldest_pending_age_seconds' => null,
            'oldest_pending_age_minutes' => null,
            'queues' => [],
            'unavailable_reason' => $reason,
        ];
    }
}
