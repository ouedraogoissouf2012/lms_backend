<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use RuntimeException;

/**
 * Garde pré-vol de la migration des clés étrangères `institution_id` (#583).
 *
 * Refuse la pose des FK tant qu'il subsiste des lignes orphelines
 * (`institution_id` pointant vers une institution inexistante). Vérifié AVANT
 * toute modification de schéma : sous MySQL, `ALTER TABLE ADD FOREIGN KEY`
 * provoque un commit implicite (DDL non transactionnel), donc un échec en cours
 * de boucle laisserait un schéma partiellement contraint. On échoue tôt, avec un
 * message actionnable, plutôt que « à l'aveugle ».
 *
 * Extraite de la migration pour être testable en isolation (§1.6 — L/D) : un
 * double de `InstitutionIntegrityInspectorInterface` suffit à exercer les deux
 * branches, sans fabriquer d'orphelins dans une table déjà contrainte.
 */
final class InstitutionForeignKeyGuard
{
    public function __construct(
        private readonly InstitutionIntegrityInspectorInterface $inspector,
    ) {
    }

    /**
     * @param  list<string>  $tables
     *
     * @throws RuntimeException si au moins une table porte des lignes orphelines.
     */
    public function ensureNoOrphans(array $tables): void
    {
        $present = $this->inspector->scopedTablesPresent($tables);
        $orphans = $this->inspector->orphans($present);

        if ($orphans !== []) {
            throw new RuntimeException($this->abortMessage($orphans));
        }
    }

    /**
     * @param  array<string, int>  $orphans
     */
    private function abortMessage(array $orphans): string
    {
        $lines = [];
        foreach ($orphans as $table => $count) {
            $lines[] = "  - {$table} : {$count} ligne(s) orpheline(s)";
        }

        return "Migration #583 annulée : des lignes orphelines (institution_id "
            ."pointant vers une institution inexistante) empêchent l'ajout des "
            ."clés étrangères sans état partiel.\n".implode("\n", $lines)."\n"
            ."Exécutez `php artisan institutions:audit-orphans` pour le détail, "
            ."nettoyez (rattachement / archivage) puis relancez la migration.";
    }
}
