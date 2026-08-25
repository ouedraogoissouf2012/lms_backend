<?php

declare(strict_types=1);

namespace App\Services\Integrity;

/**
 * Contrat d'écriture en quarantaine (#541 — R4 non-destruction).
 *
 * Abstraction dont dépendent tous les réparateurs d'intégrité (§1.6 — D) : ils ne
 * connaissent jamais la table `orphan_row_archive`, et un double suffit à vérifier
 * en test qu'ils archivent AVANT de retirer.
 */
interface ArchivedRowWriterInterface
{
    /**
     * Copie des lignes en quarantaine. Rejouable sans duplication.
     *
     * @param  iterable<int, array<string, mixed>>  $rows  Lignes INTÉGRALES, portant `id`.
     * @return int Nombre de lignes réellement écrites.
     */
    public function write(string $sourceTable, iterable $rows, string $reason): int;
}
