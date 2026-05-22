<?php

declare(strict_types=1);

namespace App\Console\Commands\Klassci;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `users.klassci_role` from `users.role` for KLASSCI-synced users.
 *
 * ## Issue #126 — préparation scale > 500k users
 *
 * Reproduit verbatim la logique de backfill inline de la migration
 * `2026_05_18_000001_add_klassci_role_to_users_table.php` (PR #118 / CRITICAL-05).
 * Permet une exécution **hors-migration** à grande échelle (>500k users) sans
 * bloquer le pipeline de déploiement orchestré (Forge, GitHub Actions, etc.).
 *
 * ## Idempotence
 *
 * Filtre `whereNotNull('klassci_id')` aligné sur la migration originale.
 * La sémantique `klassci_role = role` est déterministe — un re-run écrit
 * la même valeur, aucun effet de bord.
 *
 * ## Usage
 *
 *   php artisan klassci:backfill-role             # défaut chunk=1000
 *   php artisan klassci:backfill-role --chunk=2000
 *   php artisan klassci:backfill-role --dry-run   # audit sans écriture
 *
 * @see app/Console/Commands/Klassci/BackfillEnseignantIdCommand.php (sister command)
 * @see database/migrations/2026_05_18_000001_add_klassci_role_to_users_table.php (migration source)
 * @see .claude/specs/backfill-command-artisan/
 */
final class BackfillRoleCommand extends Command
{
    /** @var string */
    protected $signature = 'klassci:backfill-role
                            {--chunk=1000 : Number of users per chunk}
                            {--dry-run : Show what would be updated without writing}';

    /** @var string */
    protected $description = 'Backfill users.klassci_role from users.role for KLASSCI-synced users (scale-out alternative to migration inline backfill).';

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');
        $dryRun    = (bool) $this->option('dry-run');

        // Borne supérieure : 10 000 protège contre une exhaustion mémoire si un
        // opérateur passe `--chunk=1000000` par erreur (chargement de 1M users
        // en mémoire d'un coup). 10k préserve la RAM même sur containers 512 MB.
        if ($chunkSize < 1 || $chunkSize > 10000) {
            $this->error("Chunk size must be between 1 and 10000, got: {$chunkSize}");
            return self::FAILURE;
        }

        $total = DB::table('users')->whereNotNull('klassci_id')->count();

        $this->info(sprintf(
            'Backfilling klassci_role for %d users (chunk=%d%s)',
            $total,
            $chunkSize,
            $dryRun ? ', DRY-RUN' : '',
        ));

        if ($total === 0) {
            $this->info('Nothing to backfill.');
            return self::SUCCESS;
        }

        $bar     = $this->output->createProgressBar($total);
        $updated = 0;

        DB::table('users')
            ->whereNotNull('klassci_id')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($users) use (&$updated, $dryRun, $bar) {
                if (!$dryRun) {
                    DB::table('users')
                        ->whereIn('id', $users->pluck('id'))
                        ->update(['klassci_role' => DB::raw('role')]);
                }
                $updated += $users->count();
                $bar->advance($users->count());
            });

        $bar->finish();
        $this->newLine();

        $this->info(($dryRun ? '[dry-run] ' : '') . "Updated {$updated} / {$total} users.");

        return self::SUCCESS;
    }
}
