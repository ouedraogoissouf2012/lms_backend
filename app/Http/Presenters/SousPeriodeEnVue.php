<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Models\SousPeriode;

/** Une sous-période rendue (#1806) : dates en jours `Y-m-d`. */
final class SousPeriodeEnVue
{
    /** @return array{id: int, training_session_id: int, libelle: string, debut: string, fin: string, ordre: int|null} */
    public static function de(SousPeriode $sousPeriode): array
    {
        return [
            'id' => $sousPeriode->id,
            'training_session_id' => $sousPeriode->training_session_id,
            'libelle' => $sousPeriode->libelle,
            'debut' => $sousPeriode->debut->toDateString(),
            'fin' => $sousPeriode->fin->toDateString(),
            'ordre' => $sousPeriode->ordre,
        ];
    }
}
