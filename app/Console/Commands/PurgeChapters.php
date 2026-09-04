<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Chapter\ChapterRetentionResult;
use App\Services\Chapter\ChapterRetentionService;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Vide la corbeille des chapitres à échéance (#674).
 *
 * La politique de rétention — durée, éligibilité, ordre de destruction — vit
 * dans {@see ChapterRetentionService}. Cette commande ne fait que la déclencher
 * et rendre compte : c'est ce qui permet de la tester sans passer par la console.
 */
final class PurgeChapters extends Command
{
    protected $signature = 'chapters:purge
        {--dry-run : Liste les chapitres éligibles sans rien détruire}
        {--apply : Applique explicitement la purge}';

    protected $description = 'Détruit définitivement les chapitres à la corbeille au-delà de la durée de rétention';

    public function handle(ChapterRetentionService $retention, LoggerInterface $logger): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        // Aucun défaut implicite : une purge définitive ne doit jamais partir
        // d'une invocation distraite, et une simulation silencieuse ne doit
        // jamais passer pour une application.
        if ($dryRun === $apply) {
            $this->error('Choisissez exactement une option: --dry-run ou --apply.');

            return self::INVALID;
        }

        $cutoff = $retention->cutoff();
        $result = new ChapterRetentionResult;
        $retention->fillInventory($result);

        $retention->trashedBeyond($cutoff)
            ->orderBy('id')
            ->chunkById($retention->chunkSize(), function ($chapters) use ($retention, $cutoff, $dryRun, $result): void {
                foreach ($chapters as $chapter) {
                    if (! $retention->eligible($chapter, $cutoff)) {
                        $result->ignored++;

                        continue;
                    }

                    $result->eligible++;

                    if ($dryRun) {
                        continue;
                    }

                    try {
                        if ($retention->purge($chapter, $cutoff)) {
                            $result->purged++;
                        } else {
                            // Course entre la lecture du lot et le verrou : le
                            // chapitre a été restauré entre-temps.
                            $result->ignored++;
                        }
                    } catch (Throwable $exception) {
                        $result->failed++;
                        $retention->logFailure($chapter, $exception);
                    }
                }
            });

        $context = [
            'mode' => $dryRun ? 'dry-run' : 'apply',
            'cutoff' => $cutoff->toIso8601String(),
            'retention_days' => $retention->retentionDays(),
            'trashed_total' => $result->trashedTotal,
            'oldest_trashed_at' => $result->oldestTrashedAt,
            'eligible' => $result->eligible,
            'purged' => $result->purged,
            'ignored' => $result->ignored,
            'failed' => $result->failed,
        ];
        $logger->info('Chapter retention completed', $context);
        $this->line(json_encode($context, JSON_THROW_ON_ERROR));

        return $result->failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
