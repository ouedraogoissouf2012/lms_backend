<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Visio\Recording\StaleRecordingFailer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;

/**
 * Filet de sécurité (#514) : marque `Failed` les enregistrements bloqués en
 * `Processing` au-delà de `config('recordings.stale_processing_minutes')`.
 *
 * Voir {@see \App\Services\Visio\Recording\StaleRecordingFailer} pour le pourquoi
 * (finaliseur provider non câblé, cf. #204).
 */
final class FailStaleRecordings extends Command
{
    protected $signature = 'recordings:fail-stale-processing';

    protected $description = 'Marque Failed les enregistrements bloqués en Processing au-delà du délai (filet de sécurité #514)';

    public function handle(
        ConfigRepository $config,
        StaleRecordingFailer $failer,
        LoggerInterface $logger,
    ): int {
        $minutes = $this->positiveInt($config->get('recordings.stale_processing_minutes'), 30);
        $chunkSize = $this->positiveInt($config->get('recordings.purge_chunk_size'), 100);
        $cutoff = now()->subMinutes($minutes);

        $failed = $failer->failStaleProcessing($cutoff, $chunkSize);

        $context = [
            'cutoff' => $cutoff->toIso8601String(),
            'stale_processing_minutes' => $minutes,
            'failed' => $failed,
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
