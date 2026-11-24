<?php

declare(strict_types=1);

namespace App\Services\Retention;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Le journal de conformité d'une purge (#719, B8) : ce qui a été détruit, quand, par quelle
 * règle. Jamais ce que la ligne contenait.
 *
 * `orphan_row_archive` stocke la ligne intégrale : s'en servir comme journal recréerait la
 * donnée effacée. Ce journal ne garde donc qu'un identifiant.
 */
interface JournalDePurge
{
    /**
     * Consigne la destruction de `$ligne` par `$politique`. Appelé dans la MÊME transaction que
     * la destruction : une purge qui échoue ne laisse pas de ligne mensongère.
     */
    public function consigner(RetentionPolicy $politique, Model $ligne, CarbonInterface $cutoff): void;
}
