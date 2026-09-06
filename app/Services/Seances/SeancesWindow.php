<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Services\Seances\Sync\StaleSeanceArchiver;
use Carbon\Carbon;

/**
 * La fenêtre de dates sur laquelle le LMS interroge l'emploi du temps KLASSCI.
 *
 * ## Pourquoi une fenêtre, et pourquoi UNE SEULE
 *
 * `emploi-temps` interrogé sans `date_debut`/`date_fin` ne rend que la **semaine
 * courante** (mesuré le 2026-09-05). Toute lecture doit donc borner explicitement.
 *
 * Mais surtout : deux lecteurs qui borneraient DIFFÉREMMENT se détruiraient l'un
 * l'autre. La liste affichée crée les lignes locales manquantes ; la
 * synchronisation planifiée estampille `seances.synced_at` sur ce qu'elle
 * retrouve chez KLASSCI ; et {@see StaleSeanceArchiver}
 * archive ensuite — `is_active = false`, motif `supprimee_klassci` — tout ce dont
 * l'estampille est nulle ou antérieure au cycle.
 *
 * Une séance visible dans la fenêtre de la LISTE mais hors de celle de la
 * SYNCHRO serait donc créée, jamais confirmée, puis archivée au cycle suivant —
 * cinq minutes plus tard, sans que rien ne l'ait supprimée chez KLASSCI.
 *
 * D'où cette classe : la fenêtre est une décision unique du système, pas un
 * paramètre que chaque appelant redécide.
 *
 * Six mois de part et d'autre couvrent une année scolaire complète dans les deux
 * sens, quel que soit le moment où l'on consulte.
 */
final class SeancesWindow
{
    /** Demi-fenêtre, en mois, de part et d'autre du jour courant. */
    public const MONTHS = 6;

    /**
     * @return array{0: string,1: string} `[date_debut, date_fin]` au format `Y-m-d`.
     */
    public static function rolling(): array
    {
        return [
            Carbon::now()->subMonths(self::MONTHS)->format('Y-m-d'),
            Carbon::now()->addMonths(self::MONTHS)->format('Y-m-d'),
        ];
    }
}
