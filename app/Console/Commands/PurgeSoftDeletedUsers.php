<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Retention\RetentionRunner;
use Illuminate\Console\Command;

/**
 * Alias historique de `purge:run users` (#690).
 *
 * ## Pourquoi la commande survit alors que son corps a disparu
 *
 * Elle est documentée, elle peut figurer dans des procédures d'exploitation, et
 * c'est une commande de DESTRUCTION : la supprimer ferait échouer un runbook au
 * pire moment. Elle garde donc exactement sa signature — `--force`, `--days` —
 * et transmet.
 *
 * Le squelette qu'elle portait (simulation par défaut, délai de grâce, découpage
 * en lots, trace d'audit avant destruction) vit désormais dans
 * {@see RetentionRunner}, en un seul exemplaire. Il était
 * recopié à la main dans quatre commandes, et son `graceDays()` était identique
 * au caractère près à celui de `institutions:purge-deleted`.
 *
 * @see app/Services/Retention/Policies/SoftDeletedUsersPolicy.php
 * @see app/Services/User/UserDeletionService.php (soft delete réversible)
 */
final class PurgeSoftDeletedUsers extends Command
{
    protected $signature = 'users:purge-deleted
        {--force : Exécute la purge réelle (par défaut : dry-run)}
        {--days=30 : Délai de grâce en jours avant purge définitive}';

    protected $description = 'Purge physiquement les utilisateurs soft-deleted au-delà du délai de grâce (RGPD, #566)';

    public function handle(): int
    {
        $this->comment('users:purge-deleted est un alias de « purge:run users ». Préférez la forme directe.');

        return $this->call('purge:run', [
            'domaine' => 'users',
            '--force' => (bool) $this->option('force'),
            '--days' => $this->option('days'),
        ]);
    }
}
