<?php

declare(strict_types=1);

namespace App\Services\Integrity;

use Illuminate\Database\DatabaseManager;

/**
 * Écrit les lignes retirées par une migration d'intégrité dans la table de
 * quarantaine `orphan_row_archive` (#541 — R4).
 *
 * `insertOrIgnore` — et non `insert` — parce qu'une migration peut être relancée
 * après un échec partiel : sous MySQL, `ALTER TABLE` déclenche un commit
 * implicite, donc l'idempotence ne peut pas reposer sur la transaction. La
 * contrepartie (`INSERT IGNORE` dégrade les erreurs en avertissements) est
 * couverte par la relecture qu'impose {@see RowQuarantine::archive()} : aucune
 * ligne n'est retirée de sa table avant que sa copie n'ait été RELUE ici.
 *
 * Le lot est vidé au fur et à mesure : la borne porte donc réellement sur la
 * mémoire, et pas seulement sur la taille des requêtes — l'appelant peut passer
 * un générateur d'un million de lignes sans les matérialiser.
 */
final class ArchivedRowWriter implements ArchivedRowWriterInterface
{
    private const CHUNK = 500;

    public function __construct(
        private readonly DatabaseManager $db,
    ) {
    }

    public function write(string $sourceTable, iterable $rows, string $reason): int
    {
        $archivedAt = now();
        $batch = [];
        $written = 0;

        foreach ($rows as $row) {
            $batch[] = [
                'source_table' => $sourceTable,
                'source_row_id' => RowIdentifier::of($row),
                'reason' => $reason,
                'payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'archived_at' => $archivedAt,
            ];

            if (count($batch) === self::CHUNK) {
                $written += $this->flush($batch);
                $batch = [];
            }
        }

        return $written + $this->flush($batch);
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     */
    private function flush(array $batch): int
    {
        return $batch === [] ? 0 : $this->db->table('orphan_row_archive')->insertOrIgnore($batch);
    }
}
