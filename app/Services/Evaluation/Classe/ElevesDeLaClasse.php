<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Classe;

use App\Models\Classe;
use App\Models\User;

/**
 * #1676 : « qui sont les élèves de cette classe ? », pour le bilan, dans les deux volets.
 *
 * - École créée depuis le LMS : les inscriptions actives de la classe ({@see LocalElevesDeLaClasse}).
 * - École KLASSCI : le roster de KLASSCI, qui fait foi ({@see KlassciElevesDeLaClasse}).
 *
 * Le volet est choisi au seul `RosterAuthorityFactory` (jamais un test du mode ici).
 */
interface ElevesDeLaClasse
{
    /**
     * @return list<EleveDeLaClasse>
     *
     * @throws \RuntimeException volet KLASSCI : refus (4xx) ou indisponibilité du roster, relayés
     *                           par le contrôleur ; jamais une classe vide fabriquée.
     */
    public function de(Classe $classe, User $lecteur): array;
}
