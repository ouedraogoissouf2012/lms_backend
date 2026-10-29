<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Chapter\LessonTimeRecomputation;
use Illuminate\Console\Command;

/**
 * Rattrapage #944 : recalcule `lesson_progress.time_spent_minutes` depuis le
 * temps des chapitres. Simulation par défaut ; n'écrit qu'avec `--apply`.
 * Idempotent : une seconde passe ne trouve plus rien à corriger.
 *
 * Usage :
 *   php artisan lessons:recompute-time-spent                   # simulation globale
 *   php artisan lessons:recompute-time-spent --institution=3   # un établissement
 *   php artisan lessons:recompute-time-spent --apply           # écrire les corrections
 */
class RecomputeLessonTimeSpent extends Command
{
    protected $signature = 'lessons:recompute-time-spent
                            {--apply : Persiste les corrections (sinon simulation, aucune écriture)}
                            {--institution= : Limiter à une institution (id)}';

    protected $description = 'Recalcule le temps passé par leçon depuis le temps des chapitres (#944, simulation par défaut).';

    public function __construct(private readonly LessonTimeRecomputation $recomputation)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $institution = $this->option('institution');

        $this->info($apply
            ? 'Recalcul en mode APPLY (écriture en base).'
            : 'Recalcul en mode SIMULATION (aucune écriture).');

        $bilan = $this->recomputation->run(
            $apply,
            ($institution === null || $institution === '') ? null : (int) $institution,
        );

        $this->line(sprintf('Couples (utilisateur, leçon) inspectés : %d', $bilan['couples']));
        $this->line(sprintf('  • à corriger : %d', $bilan['a_corriger']));
        $this->line(sprintf('  • corrigés : %d', $bilan['corriges']));

        return Command::SUCCESS;
    }
}
