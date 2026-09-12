<?php

declare(strict_types=1);

namespace App\Services\Retention;

use App\Services\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Le moteur de purge — ce qu'AUCUNE politique ne peut contourner (#690).
 *
 * Les garanties vivent ici, en un seul exemplaire, plutôt que recopiées dans
 * chaque commande où l'une d'elles finissait par manquer :
 *
 *  - **rien n'est détruit sans ordre explicite** : `$destroy` vaut `false` par
 *    défaut, et c'est l'appelant qui doit le renverser ;
 *  - **découpage en lots**, toujours : la RAM ne dépend jamais de l'arriéré.
 *    `PurgeSoftDeletedInstitutions` faisait un `->get()` sur tout le trashed ;
 *  - **trace d'audit avant destruction**, et seulement pour ce qui est
 *    réellement détruit. `PurgeSeanceRecordings` n'en écrivait aucune ;
 *  - **comptes honnêtes** : détruits et refusés sont distingués des éligibles.
 *    L'ancien message final de `PurgeSoftDeletedInstitutions` annonçait comme
 *    purgées des institutions que la boucle venait d'épargner.
 *
 * Aucune planification ici, ni ailleurs : une destruction définitive n'est pas
 * un geste passif. Elle se déclenche à la main, après lecture d'une simulation.
 */
final class RetentionRunner
{
    private const TAILLE_DE_LOT = 100;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  null|callable(string, ?string): void  $onItem  Reçoit la description
     *                                                        de chaque ligne, et la raison du refus s'il y en a une.
     */
    public function run(
        RetentionPolicy $policy,
        CarbonInterface $cutoff,
        bool $destroy = false,
        ?callable $onItem = null,
    ): RetentionOutcome {
        $resultat = new RetentionOutcome;

        $policy->eligible($cutoff)->chunkById(
            self::TAILLE_DE_LOT,
            /** @param Collection<int, Model> $lignes */
            function ($lignes) use ($policy, $destroy, $onItem, $resultat): void {
                foreach ($lignes as $ligne) {
                    $this->traiter($policy, $ligne, $destroy, $onItem, $resultat);
                }
            }
        );

        return $resultat;
    }

    /**
     * @param  null|callable(string, ?string): void  $onItem
     */
    private function traiter(
        RetentionPolicy $policy,
        Model $ligne,
        bool $destroy,
        ?callable $onItem,
        RetentionOutcome $resultat,
    ): void {
        $resultat->eligible++;
        $description = $policy->describe($ligne);
        $refus = $policy->refuses($ligne);

        if ($onItem !== null) {
            $onItem($description, $refus);
        }

        if ($refus !== null) {
            $resultat->refused++;

            return;
        }

        if (! $destroy) {
            return;
        }

        // Tracer PUIS détruire : `forceDelete()` efface aussi la cible d'audit.
        $this->audit->logSecurityEvent($policy->auditAction(), $ligne, [
            'description' => $description,
        ]);

        $policy->purge($ligne);
        $resultat->purged++;
    }
}
