<?php

declare(strict_types=1);

namespace App\Services\Sync\Classes;

use App\Models\Classe;

/**
 * École en mode autonome : ses inscriptions sont décidées par l'établissement
 * (import, adhésion par code), jamais par une liste KLASSCI, même quand une classe
 * d'origine KLASSCI est encore synchronisée.
 */
final class AucuneReconciliation implements ReconciliationDesInscriptions
{
    public function reconcilier(Classe $classe, ListeKlassciDesEleves $liste): void {}
}
