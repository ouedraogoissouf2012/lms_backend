<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

/**
 * #539 — Budget-temps souple pour les jobs de maintenance drainés par
 * `queue:work --stop-when-empty --max-time=55` ({@see \App\Console\Commands\Scheduler\QueueDrainCommand}).
 *
 * ## Problème
 *
 * `--max-time` n'interrompt le worker qu'ENTRE deux jobs. Un seul job long
 * tournait donc jusqu'à son `$timeout` (jusqu'à 600 s pour la sync KLASSCI) en
 * tenant le worker, retardant les jobs `high` (auto-close visio, détection de
 * déconnexion) jusqu'à ~10 min.
 *
 * ## Approche
 *
 * Ces jobs de maintenance sont IDEMPOTENTS (chaque run requête « le travail
 * restant »). On traite donc les enregistrements par lots (`chunkById`) et on
 * s'arrête PROPREMENT dès que le budget-temps souple est dépassé — bien avant le
 * `$timeout` dur. Le travail non traité est repris au drain suivant, sans perte
 * ni faux échec (le job se termine avec succès).
 *
 * Les propriétés sont publiques pour rester surchargeables en test (budget 0 =
 * arrêt après le 1er lot ; `drainChunkSize` réduit).
 */
trait InteractsWithDrainBudget
{
    /**
     * Budget-temps souple d'un run, en secondes. Doit rester strictement sous le
     * `$timeout` dur du job ET sous le `--max-time` du drain (55 s).
     */
    public int $drainBudgetSeconds = 45;

    /** Taille de lot pour `chunkById`. */
    public int $drainChunkSize = 200;

    /**
     * Vrai dès que le budget-temps souple est dépassé depuis `$startedAt`
     * (un timestamp `microtime(true)` pris au début du run).
     */
    private function drainBudgetExceeded(float $startedAt): bool
    {
        return (microtime(true) - $startedAt) >= $this->drainBudgetSeconds;
    }
}
