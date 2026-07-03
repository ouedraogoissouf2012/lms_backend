<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\Scheduler\SchedulerHealthcheckCommand;
use App\Console\Commands\Scheduler\SchedulerHeartbeatCommand;
use App\Services\Scheduler\SchedulerHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Issue #369 — Tests des commandes `scheduler:heartbeat` et
 * `scheduler:healthcheck`.
 *
 * Contrat monitoring : exit 0 si le scheduler a battu il y a < 5 minutes,
 * exit 1 sinon (marqueur absent OU périmé) + log d'erreur exploitable.
 * Le succès est silencieux : sous cron cPanel, toute sortie génère un
 * e-mail — on ne veut être notifié QUE des échecs.
 *
 * @see docs/DEPLOYMENT_OPS.md
 */
#[CoversClass(SchedulerHealthcheckCommand::class)]
#[CoversClass(SchedulerHeartbeatCommand::class)]
final class SchedulerHealthcheckCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');
        Cache::store('array')->flush();
    }

    public function test_heartbeat_command_writes_the_liveness_marker(): void
    {
        $this->travelTo(Carbon::parse('2026-07-03 10:00:00'));

        $this->artisan('scheduler:heartbeat')->assertExitCode(0);

        $lastBeat = $this->app->make(SchedulerHeartbeat::class)->lastBeatAt();
        $this->assertNotNull($lastBeat);
        $this->assertTrue($lastBeat->equalTo('2026-07-03 10:00:00'));
    }

    public function test_healthcheck_exits_zero_when_beat_is_fresh(): void
    {
        $this->travelTo(Carbon::parse('2026-07-03 10:00:00'));
        $this->artisan('scheduler:heartbeat');

        $this->travelTo(Carbon::parse('2026-07-03 10:04:00'));

        $this->artisan('scheduler:healthcheck')->assertExitCode(0);
    }

    public function test_healthcheck_exits_one_when_beat_is_stale(): void
    {
        $this->travelTo(Carbon::parse('2026-07-03 10:00:00'));
        $this->artisan('scheduler:heartbeat');

        $this->travelTo(Carbon::parse('2026-07-03 10:06:00'));

        $this->artisan('scheduler:healthcheck')
            ->expectsOutputToContain('Scheduler KO')
            ->assertExitCode(1);
    }

    public function test_healthcheck_exits_one_when_marker_is_missing(): void
    {
        $this->artisan('scheduler:healthcheck')
            ->expectsOutputToContain('Scheduler KO')
            ->assertExitCode(1);
    }

    public function test_healthcheck_logs_an_error_when_scheduler_is_dead(): void
    {
        Log::spy();

        $this->artisan('scheduler:healthcheck')->assertExitCode(1);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, '[SchedulerHealthcheck]')
                    && array_key_exists('last_beat_at', $context);
            });
    }
}
