<?php

declare(strict_types=1);

namespace App\Services\Retention;

/**
 * Ce qu'une exécution de purge a réellement fait (#690).
 *
 * Trois nombres, pas un. L'ancienne sortie annonçait « ✓ N purgé(s) » en
 * réutilisant le compte d'ÉLIGIBLES, alors que la boucle pouvait en ignorer :
 * `PurgeSoftDeletedInstitutions` refusait les institutions encore peuplées, et
 * son message final les comptait quand même. Un chiffre faux sur une commande
 * de destruction, c'est une enquête inutile la semaine suivante.
 *
 * `refused` n'est PAS `failed` : refuser de détruire une institution qui a
 * encore des lignes filles est le comportement voulu, pas une panne.
 */
final class RetentionOutcome
{
    public function __construct(
        /** Lignes que la requête a retenues. */
        public int $eligible = 0,
        /** Lignes réellement détruites. */
        public int $purged = 0,
        /** Lignes que la politique a délibérément épargnées. */
        public int $refused = 0,
    ) {}

    /**
     * Ce qui serait détruit si l'ordre était donné. Compté aussi en simulation :
     * l'exploitant doit voir d'avance ce que la politique va épargner, pas le
     * découvrir après coup.
     */
    public function destructible(): int
    {
        return $this->eligible - $this->refused;
    }
}
