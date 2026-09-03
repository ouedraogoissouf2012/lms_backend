<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Visio\Recording\StaleRecordingFailer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;

/**
 * Filet de sécurité : referme les enregistrements restés dans un statut ACTIF,
 * qui conservent leur verrou et bloquent tout nouvel enregistrement.
 *
 * Deux passes, deux seuils **volontairement distincts** :
 * - `Processing` (#514) — arrêté mais jamais finalisé, daté sur `stopped_at` ;
 * - `Recording` (#680) — jamais arrêté, daté sur `seances.visio_ended_at` avec
 *   un plafond de durée absolu.
 *
 * Le nom historique de la commande était `recordings:fail-stale-processing`. Il
 * est devenu mensonger le jour où la seconde passe est apparue.
 *
 * @see \App\Services\Visio\Recording\StaleRecordingFailer
 */
final class FailStaleRecordings extends Command
{
    protected $signature = 'recordings:fail-stale';

    protected $description = 'Marque Failed les enregistrements bloqués en Processing ou en Recording (filets de sécurité #514 et #680)';

    public function handle(
        ConfigRepository $config,
        StaleRecordingFailer $failer,
        LoggerInterface $logger,
    ): int {
        $chunkSize = $this->positiveInt($config->get('recordings.purge_chunk_size'), 100);

        $processingMinutes = $this->positiveInt($config->get('recordings.stale_processing_minutes'), 30);
        $graceMinutes = $this->positiveInt($config->get('recordings.stale_recording_grace_minutes'), 15);
        $maxHours = $this->positiveInt($config->get('recordings.max_recording_hours'), 6);

        $processingCutoff = now()->subMinutes($processingMinutes);
        $visioEndedBefore = now()->subMinutes($graceMinutes);
        $startedBefore = now()->subHours($maxHours);

        $context = [
            'processing_cutoff' => $processingCutoff->toIso8601String(),
            'visio_ended_before' => $visioEndedBefore->toIso8601String(),
            'started_before' => $startedBefore->toIso8601String(),
            'stale_processing_minutes' => $processingMinutes,
            'stale_recording_grace_minutes' => $graceMinutes,
            'max_recording_hours' => $maxHours,
            'failed_processing' => $failer->failStaleProcessing($processingCutoff, $chunkSize),
            'failed_recording' => $failer->failStaleRecording($visioEndedBefore, $startedBefore, $chunkSize),
        ];

        $logger->info('Fail-stale recordings completed', $context);
        $this->line(json_encode($context, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function positiveInt(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
