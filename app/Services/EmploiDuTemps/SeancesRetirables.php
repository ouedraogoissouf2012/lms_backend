<?php

declare(strict_types=1);

namespace App\Services\EmploiDuTemps;

use App\Models\LigneEmploiDuTemps;
use App\Models\Seance;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ce qu'une grille modifiée peut retirer (#1669) : les séances FUTURES générées par une ligne, jamais une séance
 * TENUE. Une séance est tenue dès qu'elle a commencé, que sa visio a démarré, ou qu'elle a une présence.
 */
final class SeancesRetirables
{
    /** @return int les séances retirées */
    public function retirerDeLaLigne(LigneEmploiDuTemps $ligne): int
    {
        $retirees = 0;
        foreach ($this->retirables($ligne)->get() as $seance) {
            $seance->delete();
            $retirees++;
        }

        return $retirees;
    }

    /** @return Builder<Seance> */
    private function retirables(LigneEmploiDuTemps $ligne): Builder
    {
        // Le scope des séances supprimées est GARDÉ : une séance déjà retirée ne se compte pas deux fois.
        return Seance::query()->withoutGlobalScope('institution')
            ->where('emploi_du_temps_ligne_id', $ligne->id)
            ->where('date_seance', '>', now())
            ->whereNull('visio_started_at')
            ->whereDoesntHave('attendances', static fn (Builder $presences) => $presences->withoutGlobalScopes());
    }
}
