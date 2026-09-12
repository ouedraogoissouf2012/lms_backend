<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Alias historique de `purge:run audit-logs` (#690, #215).
 *
 * ## Le piège évité ici, et pourquoi il méritait d'être écrit
 *
 * Cette commande n'a **aucun drapeau destructeur** : invoquée, elle purge. Et
 * elle est **planifiée quotidiennement** (`routes/console.php:98`).
 *
 * La transformer en alias naïf de `purge:run audit-logs` l'aurait fait hériter
 * du défaut du moteur — la simulation. Le planificateur aurait continué à
 * l'exécuter, à rapporter un succès, et **plus rien n'aurait été purgé** : le
 * journal d'audit aurait grossi sans limite, sans qu'aucune alerte ne se
 * déclenche. Très exactement la classe de défaut que ce dépôt passe son temps à
 * débusquer — un vert qui ne prouve rien.
 *
 * L'alias transmet donc `--force` : le comportement observable est identique à
 * ce qu'il était. Le `--dry-run` historique reste offert, et l'inverse alors.
 *
 * ## L'asymétrie est assumée
 *
 * `purge:run` simule par défaut ; cet alias détruit par défaut. Ce n'est pas une
 * incohérence oubliée, c'est la préservation d'un contrat que le planificateur
 * consomme depuis #215. La forme directe, elle, obéit à la règle commune.
 *
 * @see app/Services/Retention/Policies/AuditLogsPolicy.php
 * @see config/audit.php
 */
final class PurgeAuditLogs extends Command
{
    protected $signature = 'audit:purge {--dry-run : Compte les entrées éligibles sans les supprimer}';

    protected $description = 'Supprime les entrées du journal d\'audit au-delà du seuil de rétention (#215)';

    public function handle(): int
    {
        return $this->call('purge:run', [
            'domaine' => 'audit-logs',
            // Sans drapeau, cette commande purgeait. Elle est planifiée : lui
            // faire hériter de la simulation par défaut arrêterait la rétention
            // en silence.
            '--force' => ! $this->option('dry-run'),
        ]);
    }
}
