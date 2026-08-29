<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\Scheduler\QueueDrainCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class QueueDrainCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_empty_drain_records_worker_heartbeat(): void
    {
        config(['queue.default' => 'database']);
        Cache::forget(QueueDrainCommand::HEARTBEAT_KEY);

        $this->artisan('queue:drain')->assertSuccessful();

        self::assertIsString(Cache::get(QueueDrainCommand::HEARTBEAT_KEY));
    }
}
