<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SeanceRecording;
use App\Services\Visio\Recording\RecordingRetentionResult;
use App\Services\Visio\Recording\SeanceRecordingRetentionService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;
use Throwable;

final class PurgeSeanceRecordings extends Command
{
    protected $signature = 'recordings:purge
        {--dry-run : Liste les éléments éligibles sans les modifier}
        {--apply : Applique explicitement la purge}';

    protected $description = 'Applique la rétention RGPD des enregistrements visio';

    public function handle(
        ConfigRepository $config,
        SeanceRecordingRetentionService $retention,
        LoggerInterface $logger,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        if ($dryRun === $apply) {
            $this->error('Choisissez exactement une option: --dry-run ou --apply.');

            return self::INVALID;
        }

        $days = $this->positiveInt($config->get('recordings.retention_days'), 365);
        $chunkSize = $this->positiveInt($config->get('recordings.purge_chunk_size'), 100);
        $cutoff = now()->subDays($days);
        $result = new RecordingRetentionResult;

        SeanceRecording::query()
            ->with(['seance', 'chapter'])
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($recordings) use ($retention, $cutoff, $dryRun, $result): void {
                foreach ($recordings as $recording) {
                    if (! $retention->eligible($recording, $cutoff)) {
                        $result->ignored++;

                        continue;
                    }

                    $result->eligible++;
                    if ($recording->provider_recording_id !== null) {
                        $result->providerFilesIgnored++;
                    }
                    if ($dryRun) {
                        continue;
                    }

                    try {
                        $chapterPurged = $retention->purge($recording, $cutoff);
                        if ($chapterPurged !== null) {
                            $result->purged++;
                            $result->chaptersPurged += $chapterPurged ? 1 : 0;
                        } else {
                            $result->ignored++;
                        }
                    } catch (Throwable $exception) {
                        $result->failed++;
                        $retention->logFailure($recording, $exception);
                    }
                }
            });

        $context = [
            'mode' => $dryRun ? 'dry-run' : 'apply',
            'cutoff' => $cutoff->toIso8601String(),
            'retention_days' => $days,
            'eligible' => $result->eligible,
            'purged' => $result->purged,
            'chapters_purged' => $result->chaptersPurged,
            'provider_files_ignored' => $result->providerFilesIgnored,
            'ignored' => $result->ignored,
            'failed' => $result->failed,
        ];
        $logger->info('Visio recording retention completed', $context);
        $this->line(json_encode($context, JSON_THROW_ON_ERROR));

        return $result->failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function positiveInt(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
