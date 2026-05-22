<?php

declare(strict_types=1);

namespace App\Console\Commands\Klassci;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `users.klassci_enseignant_id` from `users.klassci_data['enseignant_id']`
 * for KLASSCI-synced users.
 *
 * ## Issue #126 — préparation scale > 500k users
 *
 * Reproduit verbatim la logique de backfill inline de la migration
 * `2026_05_19_000001_add_klassci_enseignant_id_to_users_table.php` (PR #122 / #119).
 * Permet une exécution **hors-migration** à grande échelle (>500k users) sans
 * bloquer le pipeline de déploiement orchestré.
 *
 * ## Idempotence
 *
 * Filtre `whereNotNull('klassci_id')->whereNull('klassci_enseignant_id')` aligné
 * sur la migration originale. Une fois le backfill effectué, le 2ᵉ run trouve
 * 0 row candidate et exit en SUCCESS sans rien faire.
 *
 * ## Skips (compteur "skipped")
 *
 * Un user est skippé silencieusement si son `klassci_data` :
 *   - est NULL ou vide
 *   - n'est pas un JSON valide (parsing échoue)
 *   - ne contient pas la clé `enseignant_id` (typique pour les étudiants)
 *   - contient `enseignant_id` non numérique
 *
 * ## Usage
 *
 *   php artisan klassci:backfill-enseignant-id
 *   php artisan klassci:backfill-enseignant-id --chunk=2000
 *   php artisan klassci:backfill-enseignant-id --dry-run
 *
 * @see app/Console/Commands/Klassci/BackfillRoleCommand.php (sister command)
 * @see database/migrations/2026_05_19_000001_add_klassci_enseignant_id_to_users_table.php (migration source)
 * @see .claude/specs/backfill-command-artisan/
 */
final class BackfillEnseignantIdCommand extends Command
{
    /** @var string */
    protected $signature = 'klassci:backfill-enseignant-id
                            {--chunk=1000 : Number of users per chunk}
                            {--dry-run : Show what would be updated without writing}';

    /** @var string */
    protected $description = 'Backfill users.klassci_enseignant_id from klassci_data["enseignant_id"] for KLASSCI-synced users (scale-out alternative).';

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');
        $dryRun    = (bool) $this->option('dry-run');

        // Borne supérieure : 10 000 protège contre une exhaustion mémoire si un
        // opérateur passe `--chunk=1000000` par erreur (chargement de 1M users
        // en mémoire d'un coup + foreach JSON decode). 10k préserve la RAM même
        // sur containers 512 MB.
        if ($chunkSize < 1 || $chunkSize > 10000) {
            $this->error("Chunk size must be between 1 and 10000, got: {$chunkSize}");
            return self::FAILURE;
        }

        $total = DB::table('users')
            ->whereNotNull('klassci_id')
            ->whereNull('klassci_enseignant_id')
            ->count();

        $this->info(sprintf(
            'Backfilling klassci_enseignant_id for %d candidate users (chunk=%d%s)',
            $total,
            $chunkSize,
            $dryRun ? ', DRY-RUN' : '',
        ));

        if ($total === 0) {
            $this->info('Nothing to backfill (idempotence: already done).');
            return self::SUCCESS;
        }

        $bar     = $this->output->createProgressBar($total);
        $updated = 0;
        $skipped = 0;

        DB::table('users')
            ->whereNotNull('klassci_id')
            ->whereNull('klassci_enseignant_id')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($users) use (&$updated, &$skipped, $dryRun, $bar) {
                foreach ($users as $u) {
                    $blob = is_string($u->klassci_data)
                        ? json_decode($u->klassci_data, true)
                        : (is_array($u->klassci_data) ? $u->klassci_data : null);

                    $enseignantId = is_array($blob) ? data_get($blob, 'enseignant_id') : null;

                    if (is_numeric($enseignantId)) {
                        if (!$dryRun) {
                            DB::table('users')
                                ->where('id', $u->id)
                                ->update(['klassci_enseignant_id' => (int) $enseignantId]);
                        }
                        $updated++;
                    } else {
                        $skipped++;
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();

        $this->info(($dryRun ? '[dry-run] ' : '') . "Updated {$updated} / {$total} users (skipped {$skipped} — no enseignant_id in blob).");

        return self::SUCCESS;
    }
}
