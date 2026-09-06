<?php

declare(strict_types=1);

namespace App\Console\Commands\Klassci;

use App\Models\Institution;
use App\Services\Klassci\Health\KlassciReachability;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;

/**
 * Relève, à intervalle régulier, la joignabilité de chaque cible KLASSCI (#744).
 *
 * ## Pourquoi cette commande existe
 *
 * L'hébergeur de KLASSCI avale les SYN par salves, puis relâche. Pour obtenir
 * une mise en liste blanche, il réclame des **horodatages précis** — et nous
 * n'en avions aucun. Le disjoncteur, désormais journalisé, ne trace que les
 * incidents déjà graves : il dit qu'un mur est tombé, pas à quelle fréquence le
 * réseau vacille.
 *
 * Ce relevé de fond transforme « ça marche parfois » en une série datée,
 * opposable.
 *
 * ## Elle ne fait jamais échouer le planificateur
 *
 * Une cible injoignable est le phénomène OBSERVÉ, pas une erreur de la sonde.
 * Rendre un code non nul ferait remonter le planificateur en échec à chaque
 * salve de filtrage, et le signal finirait ignoré — l'inverse du but.
 */
final class ProbeKlassciReachabilityCommand extends Command
{
    protected $signature = 'klassci:probe';

    protected $description = 'Relève le temps de connexion vers chaque API KLASSCI active';

    public function handle(KlassciReachability $reachability, LoggerInterface $logger): int
    {
        $cibles = Institution::query()
            ->where('is_active', true)
            ->whereNotNull('klassci_api_url')
            ->get(['slug', 'klassci_api_url']);

        foreach ($cibles as $institution) {
            $baseUrl = (string) $institution->klassci_api_url;
            $mesure = $reachability->probe($baseUrl);

            // `warning` et non `error` : une cible filtrée est un fait mesuré,
            // pas une panne de notre système. Le niveau `error` reste réservé à
            // l'ouverture du disjoncteur, qui coupe réellement le service.
            $logger->log(
                $mesure->reachable ? 'info' : 'warning',
                'KLASSCI reachability',
                ['institution' => $institution->slug, 'base_url' => $baseUrl] + $mesure->toLogContext(),
            );

            $this->line(sprintf(
                '%-20s %s',
                $institution->slug,
                $mesure->reachable ? "joignable ({$mesure->connectMs} ms)" : "INJOIGNABLE — {$mesure->error}",
            ));
        }

        return self::SUCCESS;
    }
}
