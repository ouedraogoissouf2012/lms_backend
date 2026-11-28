<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Retention\RetentionRegistry;
use App\Services\Retention\RetentionRunner;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;

/**
 * La purge PLANIFIÉE des comptes supprimés (#1705) : elle applique la durée du tableau des durées
 * (`config/conservation.php`), par la politique `users` et le moteur commun, rien d'autre.
 *
 * ## Pourquoi pas `purge:run users --force`
 *
 * `purge:run` est la commande de destruction MANUELLE, jamais planifiée : elle accepte `--days`, qui avance le seuil
 * à volonté. Celle-ci ne prend aucune durée : une horloge n'applique que la durée déclarée. La purge d'une école
 * entière n'a pas d'équivalent planifié, par décision ; elle reste `purge:run institutions`.
 *
 * ## Ce que l'on trace
 *
 * Chaque compte détruit, au journal d'audit (`user.purged`, par le moteur). Chaque compte épargné, au journal
 * applicatif : la raison seulement, pas l'adresse (une donnée personnelle n'a rien à faire dans un journal). La
 * simulation `purge:run users` nomme les comptes à qui doit les retrouver.
 */
final class PurgeComptesSupprimes extends Command
{
    protected $signature = 'comptes:purge
        {--apply : Détruit réellement. Sans ce drapeau, la commande se contente de simuler}';

    protected $description = 'Purge planifiée des comptes supprimés, à la durée du tableau des durées (#1705)';

    public function handle(RetentionRegistry $registre, RetentionRunner $moteur, LoggerInterface $journal): int
    {
        $politique = $registre->get('users');
        if ($politique === null) {
            $this->error('La politique « users » est absente du registre.');

            return self::FAILURE;
        }

        $jours = $politique->defaultGraceDays();
        $detruire = (bool) $this->option('apply');
        $resultat = $moteur->run(
            $politique,
            now()->subDays($jours),
            $detruire,
            static function (string $description, ?string $refus) use ($detruire, $journal): void {
                if ($refus !== null && $detruire) {
                    $journal->warning('Compte supprimé épargné par la purge planifiée', ['raison' => $refus]);
                }
            },
        );

        $this->info($detruire
            ? "✓ {$resultat->purged} compte(s) détruit(s) (grâce {$jours} j), {$resultat->refused} épargné(s)."
            : "[SIMULATION] {$resultat->destructible()} compte(s) seraient détruits (grâce {$jours} j), {$resultat->refused} épargné(s).");

        return self::SUCCESS;
    }
}
