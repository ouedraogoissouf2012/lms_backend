<?php

declare(strict_types=1);

namespace App\Services\Integrity;

/**
 * Choisit, parmi les lignes en doublon d'un même groupe, celle qui SURVIT (#541).
 *
 * ## Pourquoi ce n'est pas un détail
 *
 * Poser un index unique impose de retirer les lignes excédentaires. Le choix de
 * la survivante n'est donc pas une commodité d'implémentation : c'est lui qui
 * décide quelles données restent visibles. Une règle naïve — « garder la plus
 * ancienne » — retire l'évaluation qui porte les copies notées au profit d'un
 * brouillon vide, ou l'inscription `actif` au profit d'une `abandonne`.
 *
 * La règle est donc EXPLICITE, choisie table par table par la migration, et
 * substituable (§1.6 — L/D).
 */
interface DuplicateSurvivorPolicy
{
    /**
     * @param  list<array<string, mixed>>  $rows  Lignes vivantes du groupe (≥ 2).
     * @return int Clé primaire de la ligne à CONSERVER.
     */
    public function survivorId(string $table, array $rows): int;
}
