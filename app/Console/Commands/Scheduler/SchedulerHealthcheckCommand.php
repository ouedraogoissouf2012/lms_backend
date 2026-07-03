<?php

declare(strict_types=1);

namespace App\Console\Commands\Scheduler;

use App\Services\Scheduler\SchedulerHeartbeat;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;

/**
 * Healthcheck du scheduler pour monitoring externe (issue #369).
 *
 *   php artisan scheduler:healthcheck
 *     exit 0 : dernier battement < 5 min (scheduler vivant)
 *     exit 1 : marqueur absent ou périmé (scheduler mort) + log d'erreur
 *
 * Contrat cron : le succès est SILENCIEUX. Sous cPanel, toute sortie d'un
 * cron déclenche un e-mail — on ne veut être notifié QUE des échecs
 * (convention Unix « no news is good news »).
 *
 * @see docs/DEPLOYMENT_OPS.md — câblage monitoring
 * @see \App\Services\Scheduler\SchedulerHeartbeat — seuil de 5 minutes
 */
final class SchedulerHealthcheckCommand extends Command
{
    protected $signature = 'scheduler:healthcheck';

    protected $description = 'Vérifie que le scheduler a battu il y a moins de 5 minutes (exit 0/1)';

    public function handle(SchedulerHeartbeat $heartbeat, LoggerInterface $logger): int
    {
        if ($heartbeat->isAlive()) {
            return self::SUCCESS;
        }

        $lastBeatAt = $heartbeat->lastBeatAt()?->toIso8601String();

        $logger->error('[SchedulerHealthcheck] Scheduler mort — aucun battement récent', [
            'last_beat_at' => $lastBeatAt,
            'threshold_minutes' => SchedulerHeartbeat::STALE_AFTER_MINUTES,
        ]);

        $this->error(sprintf(
            'Scheduler KO — dernier battement : %s (seuil : %d min). Vérifier le cron `schedule:run` (docs/DEPLOYMENT_OPS.md).',
            $lastBeatAt ?? 'jamais',
            SchedulerHeartbeat::STALE_AFTER_MINUTES,
        ));

        return self::FAILURE;
    }
}
