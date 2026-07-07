<?php

declare(strict_types=1);

namespace App\Console\Commands\Scheduler;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

/**
 * Healthcheck cPanel-safe de la queue database.
 *
 * Succès silencieux : sous cron cPanel, une sortie standard déclenche souvent
 * un e-mail. On écrit seulement en cas d'incident.
 */
final class QueueHealthcheckCommand extends Command
{
    protected $signature = 'queue:healthcheck
        {--max-pending=1000 : Nombre maximal de jobs pending accepte}
        {--max-age-minutes=5 : Age maximal du plus vieux job pending}
        {--max-failed=0 : Nombre maximal de jobs echoues accepte}';

    protected $description = 'Vérifie failed_jobs, profondeur et âge de la queue database';

    public function handle(LoggerInterface $logger): int
    {
        $maxPending = $this->positiveIntOption('max-pending');
        $maxAgeMinutes = $this->positiveIntOption('max-age-minutes');
        $maxFailed = $this->nonNegativeIntOption('max-failed');

        $pendingCount = DB::table('jobs')->whereNull('reserved_at')->count();
        $failedCount = DB::table('failed_jobs')->count();
        $oldestPendingTimestamp = DB::table('jobs')
            ->whereNull('reserved_at')
            ->min('created_at');
        $oldestPendingAgeMinutes = $this->ageInMinutes($oldestPendingTimestamp);

        $violations = [];

        if ($pendingCount > $maxPending) {
            $violations[] = "pending={$pendingCount} > {$maxPending}";
        }

        if ($failedCount > $maxFailed) {
            $violations[] = "failed={$failedCount} > {$maxFailed}";
        }

        if ($oldestPendingAgeMinutes !== null && $oldestPendingAgeMinutes > $maxAgeMinutes) {
            $violations[] = "oldest_pending_age={$oldestPendingAgeMinutes}min > {$maxAgeMinutes}min";
        }

        if ($violations === []) {
            return self::SUCCESS;
        }

        $context = [
            'pending_count' => $pendingCount,
            'failed_count' => $failedCount,
            'oldest_pending_age_minutes' => $oldestPendingAgeMinutes,
            'max_pending' => $maxPending,
            'max_failed' => $maxFailed,
            'max_age_minutes' => $maxAgeMinutes,
            'violations' => $violations,
        ];

        $logger->error('[QueueHealthcheck] Queue database en alerte', $context);
        $this->error('Queue KO - '.implode(', ', $violations));

        return self::FAILURE;
    }

    private function positiveIntOption(string $name): int
    {
        $value = (int) $this->option($name);

        return max(1, $value);
    }

    private function nonNegativeIntOption(string $name): int
    {
        $value = (int) $this->option($name);

        return max(0, $value);
    }

    private function ageInMinutes(mixed $timestamp): ?int
    {
        if (! is_numeric($timestamp)) {
            return null;
        }

        return (int) Carbon::createFromTimestamp((int) $timestamp)->diffInMinutes(now());
    }
}
