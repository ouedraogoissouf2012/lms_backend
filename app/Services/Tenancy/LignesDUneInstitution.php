<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

/**
 * Ce qu'une institution possède encore, table par table (#719).
 *
 * Interface séparée plutôt qu'une méthode de plus sur {@see InstitutionIntegrityInspectorInterface} :
 * ses doubles de test n'ont pas à connaître une question qu'ils ne posent pas. C'est le MÊME
 * inspecteur qui l'implémente ({@see InstitutionIntegrityInspector}) : pas de second inspecteur.
 */
interface LignesDUneInstitution
{
    /**
     * Les tables, parmi `$tables`, qui portent encore au moins une ligne de l'institution.
     *
     * Lu par la requête brute, filtré explicitement sur `institution_id` : aucun scope
     * multi-tenant ne s'applique, donc aucun ne peut masquer un filtre manquant.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function tablesPeuplees(int $institutionId, array $tables): array;
}
