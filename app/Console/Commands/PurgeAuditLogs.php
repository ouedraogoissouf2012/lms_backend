<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Élague les entrées d'audit au-delà du seuil de rétention (#215).
 *
 * Le journal est append-only ; cette commande est le SEUL mécanisme de
 * suppression, planifié quotidiennement. Le seuil vient de
 * `config('audit.retention_days')` (≥ 90 j pour SOC2/GDPR).
 *
 *   php artisan audit:purge            # purge réelle
 *   php artisan audit:purge --dry-run  # compte sans supprimer
 *
 * @see config/audit.php
 * @see routes/console.php (planification quotidienne)
 */
final class PurgeAuditLogs extends Command
{
    protected $signature = 'audit:purge {--dry-run : Compte les entrées éligibles sans les supprimer}';

    protected $description = 'Supprime les entrées du journal d\'audit au-delà du seuil de rétention (#215)';

    public function handle(ConfigRepository $config): int
    {
        $retentionDays = (int) $config->get('audit.retention_days', 365);
        $cutoff = now()->subDays($retentionDays);

        $query = AuditLog::where('created_at', '<', $cutoff);
        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info("[DRY-RUN] {$count} entrée(s) antérieure(s) à {$cutoff->toDateString()} seraient supprimées.");
            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("✓ {$deleted} entrée(s) d'audit supprimée(s) (antérieures à {$cutoff->toDateString()}, rétention {$retentionDays} j).");

        return self::SUCCESS;
    }
}
