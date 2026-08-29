<?php

declare(strict_types=1);

namespace App\Services\Integrity;

/**
 * Clé étrangère que l'on s'apprête à poser (#541).
 *
 * Objet-valeur immuable : décrit `table.colonne -> tableRéférencée.colonneRéférencée`
 * sans rien exécuter. Permet aux réparateurs d'intégrité d'être paramétrés plutôt
 * que d'embarquer un nom de table en dur — donc exerçables sur des tables
 * synthétiques en test (§1.6 — L).
 */
final readonly class ForeignKeyCandidate
{
    public function __construct(
        public string $table,
        public string $column,
        public string $referencedTable,
        public string $referencedColumn = 'id',
    ) {
    }

    /**
     * Motif d'archivage porté en quarantaine — stable, donc rejouable.
     */
    public function reason(): string
    {
        return "fk:{$this->table}.{$this->column}";
    }
}
