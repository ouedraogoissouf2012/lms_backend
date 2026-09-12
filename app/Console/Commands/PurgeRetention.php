<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Retention\RetentionRegistry;
use App\Services\Retention\RetentionRunner;
use Illuminate\Console\Command;

/**
 * La commande unique de purge (#690).
 *
 * ## Un seul vocabulaire, parce qu'il s'agit de détruire
 *
 * Six commandes de purge coexistaient avec **trois** drapeaux différents pour
 * la même intention : `--force` chez les unes, `--apply` chez les autres,
 * `--dry-run` ailleurs. Sur une commande qui détruit définitivement, se tromper
 * de drapeau n'est pas une gêne.
 *
 * Ici : **on simule par défaut, on détruit sur `--force`**, et c'est tout.
 *
 * ## Jamais planifiée
 *
 * Cette commande n'apparaît pas dans `routes/console.php` et ne doit pas y
 * apparaître. Une destruction définitive se déclenche à la main, après lecture
 * d'une simulation — pas parce qu'une horloge a sonné.
 */
final class PurgeRetention extends Command
{
    protected $signature = 'purge:run
        {domaine? : Domaine à purger. Omis, la commande liste ceux qui existent}
        {--force : Détruit réellement. Sans ce drapeau, la commande se contente de simuler}
        {--days= : Délai de grâce en jours (défaut : celui de la politique)}
        {--list=20 : Nombre de lignes détaillées à afficher}';

    protected $description = 'Purge définitive d\'un domaine, derrière sa politique de rétention (#690)';

    public function handle(RetentionRegistry $registre, RetentionRunner $moteur): int
    {
        $domaine = $this->argument('domaine');

        if (! is_string($domaine) || $domaine === '') {
            $this->info('Domaines purgeables : '.implode(', ', $registre->keys()));

            return self::SUCCESS;
        }

        $politique = $registre->get($domaine);
        if ($politique === null) {
            $this->error("Domaine inconnu : {$domaine}. Connus : ".implode(', ', $registre->keys()));

            return self::INVALID;
        }

        $jours = $this->graceDays($politique->defaultGraceDays());
        $cutoff = now()->subDays($jours);
        $detruire = (bool) $this->option('force');
        $restant = $this->detailLimit();

        $resultat = $moteur->run(
            $politique,
            $cutoff,
            $detruire,
            function (string $description, ?string $refus) use (&$restant): void {
                if ($restant-- <= 0) {
                    return;
                }

                $refus === null
                    ? $this->line("  • {$description}")
                    : $this->warn("  ↷ {$description} — {$refus}");
            }
        );

        $date = $cutoff->toDateString();
        $quoi = $politique->label();

        if (! $detruire) {
            $this->info(
                "[SIMULATION] {$resultat->destructible()} {$quoi} antérieur(s) à {$date} "
                ."seraient détruits, {$resultat->refused} épargné(s). Utilisez --force pour exécuter."
            );

            return self::SUCCESS;
        }

        $this->info("✓ {$resultat->purged} {$quoi} détruit(s) définitivement (antérieur(s) à {$date}, grâce {$jours} j).");

        if ($resultat->refused > 0) {
            $this->warn("↷ {$resultat->refused} épargné(s) — voir les raisons ci-dessus.");
        }

        return self::SUCCESS;
    }

    /**
     * Délai de grâce validé. `option()` rend `mixed` → garde `is_numeric` avant
     * cast (le niveau 9 interdit `(int) mixed`).
     *
     * Un délai NÉGATIF placerait le seuil dans le futur et détruirait des
     * suppressions récentes : on retombe sur le défaut de la politique plutôt
     * que d'exécuter une purge dangereuse. `--days=0` reste un choix explicite
     * légitime — « détruire tout ce qui est en corbeille ».
     */
    private function graceDays(int $defaut): int
    {
        $brut = $this->option('days');

        if (! is_numeric($brut)) {
            return $defaut;
        }

        $jours = (int) $brut;

        return $jours < 0 ? $defaut : $jours;
    }

    private function detailLimit(): int
    {
        $brut = $this->option('list');

        return is_numeric($brut) && (int) $brut >= 0 ? (int) $brut : 20;
    }
}
