<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tenancy\InstitutionIntegrityInspectorInterface;
use Illuminate\Console\Command;

/**
 * Mesure préalable EN LECTURE SEULE de l'intégrité de `institution_id` (#583).
 *
 * Pour chacune des tables tenant-scopées (`config/tenancy.php`), compte :
 *   - les lignes à `institution_id` NULL ;
 *   - les lignes ORPHELINES (institution_id non nul sans institution existante).
 *
 * C'est ce rapport qui détermine le périmètre réel du nettoyage AVANT que la
 * migration FK puisse s'appliquer (sa garde pré-vol refuse tant qu'il reste des
 * orphelins). N'émet AUCUNE écriture — audit strictement non destructif.
 *
 *   php artisan institutions:audit-orphans           # tableau lisible
 *   php artisan institutions:audit-orphans --json     # sortie machine
 *
 * @see App\Services\Tenancy\InstitutionIntegrityInspectorInterface
 * @see database/migrations/2026_08_15_140000_add_institution_id_foreign_keys.php
 */
final class AuditInstitutionOrphans extends Command
{
    protected $signature = 'institutions:audit-orphans {--json : Sortie JSON pour consignation machine}';

    protected $description = 'Audite (lecture seule) les lignes NULL et orphelines sur institution_id (#583)';

    public function handle(InstitutionIntegrityInspectorInterface $inspector): int
    {
        /** @var list<string> $configured */
        $configured = config('tenancy.institution_scoped_tables', []);
        $report = $inspector->report($configured);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->renderTable($report);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{null: int, orphan: int}>  $report
     */
    private function renderTable(array $report): int
    {
        $rows = [];
        $totalNull = 0;
        $totalOrphan = 0;

        foreach ($report as $table => $counts) {
            $rows[] = [$table, $counts['null'], $counts['orphan']];
            $totalNull += $counts['null'];
            $totalOrphan += $counts['orphan'];
        }

        $this->table(['Table', 'institution_id NULL', 'Orphelines'], $rows);
        $this->info("Total : {$totalNull} ligne(s) NULL, {$totalOrphan} ligne(s) orpheline(s) sur ".count($report).' table(s).');

        if ($totalOrphan > 0) {
            $this->warn('⚠ Des orphelins subsistent : la migration FK #583 REFUSERA de s\'appliquer tant qu\'ils ne sont pas nettoyés.');
        }

        return self::SUCCESS;
    }
}
