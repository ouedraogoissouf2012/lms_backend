<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Evaluation\EvaluationScoreRecomputationService;
use Illuminate\Console\Command;

/**
 * Remédiation #564 : recalcule les scores d'évaluation faussés à 0 par le bug de
 * format (soumissions historiques stockées en LISTE). Dry-run par défaut ; écrit
 * uniquement avec `--apply`. Ne pousse RIEN vers KLASSCI (action externe manuelle).
 *
 * Usage :
 *   php artisan evaluations:recompute-scores                 # dry-run global
 *   php artisan evaluations:recompute-scores --evaluation=42 # cibler une éval
 *   php artisan evaluations:recompute-scores --apply         # écrire les corrections
 */
class RecomputeEvaluationScores extends Command
{
    protected $signature = 'evaluations:recompute-scores
                            {--apply : Persiste les corrections (sinon dry-run, aucune écriture)}
                            {--evaluation= : Limiter à une évaluation (id)}
                            {--institution= : Limiter à une institution (id)}';

    protected $description = 'Recalcule les scores d\'évaluation faussés à 0 par le bug de format #564 (dry-run par défaut).';

    public function __construct(private readonly EvaluationScoreRecomputationService $recomputation)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->info($apply
            ? '⚙️  Recalcul en mode APPLY (écriture en base).'
            : '🔎 Recalcul en mode DRY-RUN (aucune écriture).');

        $results = $this->recomputation->recompute(
            $apply,
            $this->intOption('evaluation'),
            $this->intOption('institution'),
        );

        $this->renderSummary($results, $apply);

        return Command::SUCCESS;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return ($value === null || $value === '') ? null : (int) $value;
    }

    /**
     * @param  list<array{submission_id:int, evaluation_id:int, outcome:string, old_note:float, new_note:float}>  $results
     */
    private function renderSummary(array $results, bool $apply): void
    {
        $changed = array_values(array_filter($results, fn ($r) => $r['outcome'] === 'changed'));
        $skippedManual = array_filter($results, fn ($r) => $r['outcome'] === 'skipped_manual');
        $unchanged = array_filter($results, fn ($r) => $r['outcome'] === 'unchanged');

        $this->newLine();
        $this->line(sprintf('Soumissions analysées : %d', count($results)));
        $this->line(sprintf('  • à corriger : %d', count($changed)));
        $this->line(sprintf('  • correction manuelle, skippées (#588) : %d', count($skippedManual)));
        $this->line(sprintf('  • déjà correctes : %d', count($unchanged)));

        if ($changed !== []) {
            $this->newLine();
            $this->table(
                ['soumission', 'évaluation', 'note avant', 'note après'],
                array_map(
                    fn ($r) => [$r['submission_id'], $r['evaluation_id'], $r['old_note'], $r['new_note']],
                    $changed
                ),
            );
        }

        $this->newLine();
        $this->info($apply
            ? sprintf('✅ %d note(s) corrigée(s) et persistée(s).', count($changed))
            : sprintf('ℹ️  DRY-RUN : %d note(s) seraient corrigées. Relancez avec --apply pour écrire.', count($changed)));
        $this->warn('⚠️  Le re-push vers KLASSCI est une action externe MANUELLE distincte (non déclenchée ici).');
    }
}
