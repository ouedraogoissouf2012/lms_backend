<?php

namespace App\Console\Commands;

use App\Jobs\CleanObsoleteSeances;
use Illuminate\Console\Command;

/**
 * Déclencheur MANUEL du job {@see CleanObsoleteSeances}, déjà planifié dans
 * `routes/console.php` (everyThirtyMinutes). Conservé volontairement (#502) comme
 * commande d'exploitation ponctuelle — ce n'est PAS un doublon oublié.
 */
class CleanObsoleteSeancesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'seances:clean-obsolete';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Nettoyer les séances qui n\'existent plus dans Klassci';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🧹 Nettoyage des séances obsolètes...');
        $this->newLine();

        // Dispatch le job de nettoyage
        CleanObsoleteSeances::dispatch();

        $this->info('✅ Job de nettoyage lancé avec succès!');
        $this->info('📋 Consultez les logs pour voir les détails.');

        return Command::SUCCESS;
    }
}
