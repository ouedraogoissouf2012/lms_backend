<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

/**
 * Contrat de l'inspecteur d'intégrité référentielle de `institution_id` (#583).
 *
 * Abstraction dont dépendent la commande d'audit ET la migration FK (§1.6-D —
 * Dependency Inversion) : les consommateurs ne connaissent jamais
 * l'implémentation concrète, et les tests peuvent substituer un double
 * (garde pré-vol de la migration testée sans avoir à fabriquer des lignes
 * orphelines dans une table déjà contrainte).
 *
 * Toutes les méthodes sont en LECTURE SEULE.
 */
interface InstitutionIntegrityInspectorInterface
{
    /**
     * Filtre les tables réellement présentes ET portant `institution_id`.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function scopedTablesPresent(array $tables): array;

    /**
     * Nombre de lignes à `institution_id` NULL.
     */
    public function nullCount(string $table): int;

    /**
     * Nombre de lignes orphelines (`institution_id` non nul sans institution
     * correspondante).
     */
    public function orphanCount(string $table): int;

    /**
     * Rapport de mesure : {table: {null, orphan}} pour les tables présentes.
     *
     * @param  list<string>  $tables
     * @return array<string, array{null: int, orphan: int}>
     */
    public function report(array $tables): array;

    /**
     * Tables ayant au moins une ligne orpheline : {table: nombre}.
     *
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    public function orphans(array $tables): array;

    /**
     * La table porte-t-elle déjà une clé étrangère sur `institution_id` ?
     */
    public function hasInstitutionForeignKey(string $table): bool;
}
