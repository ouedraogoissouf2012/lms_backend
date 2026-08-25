<?php

declare(strict_types=1);

namespace App\Services\Integrity;

use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Met des lignes en quarantaine, et ne les retire qu'une fois la copie VÉRIFIÉE
 * (#541 — R4).
 *
 * Point de passage unique du geste « archiver avant de retirer », partagé par
 * {@see OrphanRowPurger} et {@see DuplicateRowRetirer} : l'ordre archive →
 * retrait ne peut pas diverger entre les deux réparateurs.
 *
 * ## La vérification n'est pas de la ceinture-bretelles
 *
 * L'écriture d'archive passe par `insertOrIgnore` — nécessaire à l'idempotence,
 * une migration pouvant être relancée après un échec partiel (sous MySQL, un DDL
 * auto-commite, donc l'idempotence ne peut pas reposer sur la transaction). Or
 * `INSERT IGNORE` DÉGRADE les erreurs en avertissements : un payload trop long
 * pour la colonne, un jeu de caractères refusé, et la ligne d'archive n'existe
 * pas — sans la moindre exception. Supprimer ensuite la ligne source la
 * détruirait définitivement. On RELIT donc l'archive avant tout retrait, et on
 * refuse d'aller plus loin si une seule copie manque.
 */
final class RowQuarantine
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ArchivedRowWriterInterface $writer,
    ) {
    }

    /**
     * Copie les lignes en quarantaine et VÉRIFIE qu'elles y sont.
     *
     * @param  list<array<string, mixed>>  $rows  Lignes INTÉGRALES, portant `id`.
     *
     * @throws RuntimeException si une copie manque à l'appel.
     */
    public function archive(string $table, array $rows, string $reason): void
    {
        if ($rows === []) {
            return;
        }

        $this->writer->write($table, $rows, $reason);

        $identifiers = RowIdentifier::allOf($rows);

        $archived = $this->db->table('orphan_row_archive')
            ->where('source_table', $table)
            ->where('reason', $reason)
            ->whereIn('source_row_id', $identifiers)
            ->count();

        if ($archived !== count($identifiers)) {
            throw new RuntimeException(
                "Quarantaine incomplète pour `{$table}` ({$archived}/"
                .count($identifiers).' ligne(s) archivée(s)) : retrait annulé '
                ."pour ne détruire aucune donnée non sauvegardée. Motif : {$reason}.",
            );
        }
    }

    /**
     * Archive puis supprime physiquement les lignes.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return int Nombre de lignes retirées de la table source.
     */
    public function purge(string $table, array $rows, string $reason): int
    {
        if ($rows === []) {
            return 0;
        }

        $this->archive($table, $rows, $reason);

        return $this->db->table($table)->whereIn('id', RowIdentifier::allOf($rows))->delete();
    }
}
