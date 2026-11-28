<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Retention\Policies\DemandesInscriptionPolicy;
use App\Services\Retention\RetentionRunner;
use Illuminate\Console\Command;

/**
 * La purge PLANIFIÉE des demandes d'inscription (#1659, loi 2013-450) : 90 jours après la décision, 365 jours sans
 * traitement. Une obligation de durée de conservation, pas un nettoyage : elle tourne chaque jour, comme
 * `chapters:purge --apply` et `recordings:purge --apply`.
 *
 * Elle passe par le moteur commun (`RetentionRunner`) : audit avant destruction, journal de purge, état relu avant
 * d'agir. `purge:run demandes-decidees` en garde la simulation à la main.
 */
final class PurgeDemandesInscription extends Command
{
    protected $signature = 'admissions:purge
        {--dry-run : Compte les demandes éligibles sans rien détruire}
        {--apply : Applique la purge}';

    protected $description = 'Purge les demandes d\'inscription au-delà de leur durée de conservation (#1659)';

    public function handle(RetentionRunner $moteur): int
    {
        $simulation = (bool) $this->option('dry-run');

        // Aucun défaut implicite : une destruction ne part jamais d'une invocation distraite.
        if ($simulation === (bool) $this->option('apply')) {
            $this->error('Choisissez exactement une option : --dry-run ou --apply.');

            return self::INVALID;
        }

        foreach ([DemandesInscriptionPolicy::decidees(), DemandesInscriptionPolicy::nonTraitees()] as $politique) {
            $resultat = $moteur->run($politique, now()->subDays($politique->defaultGraceDays()), ! $simulation);
            $this->info($simulation
                ? sprintf('%s : %d à purger', $politique->key(), $resultat->destructible())
                : sprintf('%s : %d purgée(s)', $politique->key(), $resultat->purged));
        }

        return self::SUCCESS;
    }
}
