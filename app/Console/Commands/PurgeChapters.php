<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Chapter\ChapterRetentionResult;
use App\Services\Chapter\ChapterRetentionService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Vide la corbeille des chapitres à échéance (#674).
 *
 * La politique de rétention — durée, périmètre, éligibilité, ordre de
 * destruction — vit dans {@see ChapterRetentionService}. Cette commande ne fait
 * que la déclencher et rendre compte : c'est ce qui permet de l'éprouver sans
 * passer par la console.
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

        // Aucun défaut implicite : une purge définitive ne doit jamais partir
        // d'une invocation distraite, et une simulation silencieuse ne doit
        // jamais passer pour une application.
        if ($dryRun === (bool) $this->option('apply')) {
            $this->error('Choisissez exactement une option: --dry-run ou --apply.');

            return self::INVALID;
        }

        $cutoff = $retention->cutoff();
        $result = new ChapterRetentionResult;

        $retention->fillInventory($result);
        $this->sweep($retention, $cutoff, $dryRun, $result);
        $this->report($logger, $retention, $cutoff, $dryRun, $result);

        return $result->failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Parcourt les chapitres échus par lots et applique la politique.
     *
     * Le parcours est ordonné par identifiant : `chunkById` avance sur
     * `id > dernier vu`, donc détruire les lignes du lot courant ne fait sauter
     * aucun élément — ce qu'une pagination par décalage ferait.
     */
    private function sweep(
        ChapterRetentionService $retention,
        CarbonInterface $cutoff,
        bool $dryRun,
        ChapterRetentionResult $result,
    ): void {
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
    }

    /**
     * Rend compte deux fois : au journal, pour l'exploitation, et sur la sortie
     * standard, pour l'opérateur qui vient de lancer la simulation.
     */
    private function report(
        LoggerInterface $logger,
        ChapterRetentionService $retention,
        CarbonInterface $cutoff,
        bool $dryRun,
        ChapterRetentionResult $result,
    ): void {
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
    }
}
