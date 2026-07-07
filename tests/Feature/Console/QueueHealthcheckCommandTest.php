<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\Scheduler\QueueHealthcheckCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(QueueHealthcheckCommand::class)]
final class QueueHealthcheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_healthcheck_exits_zero_when_queue_is_healthy(): void
    {
        $this->artisan('queue:healthcheck')
            ->assertExitCode(0);
    }

    public function test_queue_healthcheck_fails_when_failed_jobs_exist(): void
    {
        Log::spy();

        DB::table('failed_jobs')->insert([
            'uuid' => 'failed-job-1',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        $this->artisan('queue:healthcheck')
            ->expectsOutputToContain('failed=1 > 0')
            ->assertExitCode(1);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, '[QueueHealthcheck]')
                && $context['failed_count'] === 1);
    }

    public function test_queue_healthcheck_fails_when_pending_depth_exceeds_threshold(): void
    {
        $this->insertPendingJob(createdAt: now()->timestamp);
        $this->insertPendingJob(createdAt: now()->timestamp);

        $this->artisan('queue:healthcheck --max-pending=1')
            ->expectsOutputToContain('pending=2 > 1')
            ->assertExitCode(1);
    }

    public function test_queue_healthcheck_fails_when_oldest_pending_job_is_too_old(): void
    {
        $this->travelTo(Carbon::parse('2026-07-07 12:00:00'));
        $this->insertPendingJob(createdAt: now()->subMinutes(6)->timestamp);

        $this->artisan('queue:healthcheck --max-age-minutes=5')
            ->expectsOutputToContain('oldest_pending_age=6min > 5min')
            ->assertExitCode(1);
    }

    private function insertPendingJob(int $createdAt): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $createdAt,
            'created_at' => $createdAt,
        ]);
    }
}
