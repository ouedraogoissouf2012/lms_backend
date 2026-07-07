<?php

namespace App\Console\Commands;

use App\Jobs\CleanOldEvaluations;
use Illuminate\Console\Command;

class CleanOldEvaluationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'evaluations:clean-old';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archiver les évaluations passées non effectuées (aucune soumission)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🧹 Nettoyage des évaluations passées...');
        $this->newLine();

        // Dispatch le job de nettoyage
        CleanOldEvaluations::dispatch();

        $this->info('✅ Job de nettoyage lancé avec succès!');
        $this->info('📋 Consultez les logs pour voir les détails.');
        $this->newLine();
        $this->info('ℹ️  Les évaluations passées sans aucune soumission seront archivées.');
        $this->info('ℹ️  Les évaluations avec au moins 1 soumission sont conservées.');

        return Command::SUCCESS;
    }
}
