<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Classe;

use App\Exceptions\BusinessException;

/**
 * #1698 (C2) : l'école peut-elle dispenser un élève d'une évaluation ? Liée par `EvaluationServiceProvider` au seul
 * `RosterAuthorityFactory::sourceForCurrentTenant` : jamais un test du mode ici.
 *
 * - École créée depuis le LMS : oui ({@see LocalePorteDesDispenses}).
 * - École KLASSCI : non ({@see KlassciPorteDesDispenses}, 409 `grades_managed_by_klassci`). La dispense n'y est pas
 *   poussée à KLASSCI : elle retirerait la note du bilan du LMS et laisserait celui de KLASSCI la compter, deux
 *   vérités qui divergeraient en silence (même règle que l'appel, #1754).
 */
interface PorteDesDispenses
{
    /** @throws BusinessException 409 dans une école KLASSCI */
    public function ouvrir(): void;
}
