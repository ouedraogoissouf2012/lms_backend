<?php

declare(strict_types=1);

namespace App\Console\Commands\Scheduler;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

final class QueueDrainCommand extends Command
{
    public const HEARTBEAT_KEY = 'queue:worker:last_heartbeat_at';

    protected $signature = 'queue:drain';

    protected $description = 'Draine les queues cPanel puis enregistre le heartbeat du worker';

    public function handle(CacheRepository $cache): int
    {
        $exitCode = $this->call('queue:work', [
            '--queue' => 'high,default,low',
            '--stop-when-empty' => true,
            '--max-time' => 55,
        ]);

        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        $cache->forever(self::HEARTBEAT_KEY, now()->toIso8601String());

        return self::SUCCESS;
    }
}
