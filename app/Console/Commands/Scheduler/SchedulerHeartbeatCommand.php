<?php

declare(strict_types=1);

namespace App\Console\Commands\Scheduler;

use App\Services\Scheduler\SchedulerHeartbeat;
use Illuminate\Console\Command;

/**
 * Pose le marqueur de vie du scheduler (issue #369).
 *
 * Planifiée chaque minute dans `routes/console.php` : si `schedule:run`
 * tourne, le marqueur est frais ; s'il ne tourne plus (cron cPanel cassé),
 * `scheduler:healthcheck` le détecte en < 10 min.
 *
 * Silencieuse par design : elle s'exécute 1 440 fois/jour, toute sortie
 * polluerait les logs du cron.
 *
 * @see \App\Console\Commands\Scheduler\SchedulerHealthcheckCommand
 */
final class SchedulerHeartbeatCommand extends Command
{
    protected $signature = 'scheduler:heartbeat';

    protected $description = 'Enregistre le battement de vie du scheduler (lu par scheduler:healthcheck)';

    public function handle(SchedulerHeartbeat $heartbeat): int
    {
        $heartbeat->beat();

        return self::SUCCESS;
    }
}
