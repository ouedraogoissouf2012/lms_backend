<?php

declare(strict_types=1);

namespace App\Services\Sync\Classes;

use App\Models\Classe;

/**
 * Ce que la synchronisation d'une classe fait des inscriptions des élèves ABSENTS
 * de sa liste, par autorité d'école (ADR-EI-01, « même route, autre source ») :
 *
 * - école KLASSCI ({@see ReconciliationKlassci}) : la liste complète fait foi ; un
 *   absent passe `inactif` ;
 * - école en mode autonome ({@see AucuneReconciliation}) : l'établissement décide de
 *   ses inscriptions ; rien ne change.
 *
 * Choisie à CHAQUE appel par `RosterAuthorityFactory::sourceForCurrentTenant` : la
 * synchronisation est reçue par des constructeurs (règle 3).
 */
interface ReconciliationDesInscriptions
{
    public function reconcilier(Classe $classe, ListeKlassciDesEleves $liste): void;
}
