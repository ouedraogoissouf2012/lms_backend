<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Maintenance\ContentCorruptionFixer;
use Illuminate\Console\Command;

/**
 * Corrige le champ `content` corrompu par le bug `$this->content` (#231).
 *
 * Dry-run PAR DÉFAUT — n'écrit rien tant que `--apply` n'est pas passé.
 * Affiche un rapport (scanné / corrompu / corrigé) par table.
 *
 *   php artisan content:fix-corruption            # simulation (lecture seule)
 *   php artisan content:fix-corruption --apply    # correction réelle + backup
 *
 * @see app/Services/Maintenance/ContentCorruptionFixer.php
 * @see database/migrations/2026_06_12_000001_create_content_corruption_backups_table.php
 */
final class FixCorruptedContent extends Command
{
    protected $signature = 'content:fix-corruption {--apply : Applique réellement les corrections (sinon simulation)}';

    protected $description = 'Corrige le champ content corrompu par le bug $this->content (#231) — dry-run par défaut';

    public function handle(ContentCorruptionFixer $fixer): int
    {
        $apply = (bool) $this->option('apply');

        if ($apply) {
            $this->warn('Mode APPLY : les corrections seront écrites (backup automatique avant écrasement).');
        } else {
            $this->info('Mode DRY-RUN : aucune écriture. Utilisez --apply pour corriger réellement.');
        }

        $report = $fixer->run($apply);

        $rows = [];
        $totalCorrupted = 0;
        $totalFixed = 0;

        foreach ($report as $table => $counts) {
            $rows[] = [$table, $counts['scanned'], $counts['corrupted'], $counts['fixed']];
            $totalCorrupted += $counts['corrupted'];
            $totalFixed += $counts['fixed'];
        }

        $this->table(['Table', 'Scannés (JSON)', 'Corrompus', 'Corrigés'], $rows);

        if ($apply) {
            $this->info("✓ {$totalFixed} row(s) corrigée(s). Backups dans `content_corruption_backups`.");
        } else {
            $this->info("→ {$totalCorrupted} row(s) corrompue(s) détectée(s). Relancez avec --apply pour corriger.");
        }

        return self::SUCCESS;
    }
}
