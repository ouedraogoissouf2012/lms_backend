<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scheduler;

use App\Services\Scheduler\SchedulerHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Issue #369 — Tests du marqueur de vie du scheduler.
 *
 * Le scheduler pose un timestamp en cache chaque minute (`beat()`) ; le
 * healthcheck le lit (`lastBeatAt()`) et décide de la santé (`isAlive()`).
 * Seuil contractuel : dernier battement < 5 minutes → vivant.
 *
 * @see \App\Services\Scheduler\SchedulerHeartbeat
 * @see \App\Console\Commands\Scheduler\SchedulerHealthcheckCommand
 */
#[CoversClass(SchedulerHeartbeat::class)]
final class SchedulerHeartbeatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Store array : le contrat porte sur l'interface Repository,
        // pas sur le driver — inutile de dépendre de la table `cache`.
        Config::set('cache.default', 'array');
        Cache::store('array')->flush();
    }

    private function heartbeat(): SchedulerHeartbeat
    {
        return $this->app->make(SchedulerHeartbeat::class);
    }

    public function test_beat_writes_a_marker_readable_via_last_beat_at(): void
    {
        $this->travelTo(Carbon::parse('2026-07-03 10:00:00'));

        $this->heartbeat()->beat();

        $lastBeat = $this->heartbeat()->lastBeatAt();
        $this->assertNotNull($lastBeat);
        $this->assertTrue($lastBeat->equalTo('2026-07-03 10:00:00'));
    }

    public function test_last_beat_at_returns_null_when_no_marker_exists(): void
    {
        $this->assertNull($this->heartbeat()->lastBeatAt());
    }

    public function test_last_beat_at_returns_null_when_marker_is_corrupted(): void
    {
        Cache::put(SchedulerHeartbeat::CACHE_KEY, 'pas-une-date');

        $this->assertNull($this->heartbeat()->lastBeatAt());
    }

    public function test_is_alive_when_last_beat_is_fresher_than_threshold(): void
    {
        $this->travelTo(Carbon::parse('2026-07-03 10:00:00'));
        $this->heartbeat()->beat();

        $this->travelTo(Carbon::parse('2026-07-03 10:04:59'));

        $this->assertTrue($this->heartbeat()->isAlive());
    }

    public function test_is_dead_when_last_beat_reaches_exactly_the_threshold(): void
    {
        // Contrat issue #369 : « dernière exécution < 5 min → exit 0 ».
        // À exactement 5 minutes, le scheduler est considéré mort.
        $this->travelTo(Carbon::parse('2026-07-03 10:00:00'));
        $this->heartbeat()->beat();

        $this->travelTo(Carbon::parse('2026-07-03 10:05:00'));

        $this->assertFalse($this->heartbeat()->isAlive());
    }

    public function test_is_dead_when_no_marker_exists(): void
    {
        $this->assertFalse($this->heartbeat()->isAlive());
    }
}
