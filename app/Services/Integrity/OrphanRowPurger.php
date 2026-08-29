<?php

declare(strict_types=1);

namespace App\Services\Integrity;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Retire les lignes orphelines qui empêchent la pose d'une clé étrangère (#541 — R3).
 *
 * Chaque ligne passe par {@see RowQuarantine} : elle est copiée intégralement
 * avant d'être supprimée, donc le geste reste réversible. Une valeur `NULL` n'est
 * jamais orpheline — une clé étrangère sur colonne nullable ne contraint que les
 * valeurs renseignées.
 *
 * Le traitement est fait par lots bornés : `esbtp_attendance` grandit avec chaque
 * visioconférence de chaque tenant, on ne charge donc jamais l'ensemble des
 * orphelins en mémoire.
 */
final class OrphanRowPurger
{
    private const CHUNK = 500;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly RowQuarantine $quarantine,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return int Nombre de lignes orphelines archivées puis supprimées.
     */
    public function purge(ForeignKeyCandidate $candidate): int
    {
        $purged = 0;

        while (true) {
            $rows = $this->nextOrphanBatch($candidate);

            if ($rows === []) {
                break;
            }

            $removed = $this->quarantine->purge($candidate->table, $rows, $candidate->reason());

            // Garde de PROGRÈS : sans elle, une passe qui ne retire rien alors que
            // la requête continue de renvoyer les mêmes lignes ferait tourner une
            // migration à l'infini, sans délai d'expiration.
            if ($removed === 0) {
                throw new RuntimeException(
                    "Impossible de retirer les lignes orphelines de `{$candidate->table}` "
                    ."(colonne `{$candidate->column}`) : aucune suppression n'a abouti "
                    .'alors que des orphelins subsistent. Migration interrompue.',
                );
            }

            $purged += $removed;
        }

        if ($purged > 0) {
            $this->logger->warning('Lignes orphelines archivées avant pose de clé étrangère', [
                'table' => $candidate->table,
                'column' => $candidate->column,
                'references' => $candidate->referencedTable,
                'purged' => $purged,
            ]);
        }

        return $purged;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nextOrphanBatch(ForeignKeyCandidate $candidate): array
    {
        $rows = $this->orphanQuery($candidate)->orderBy('id')->limit(self::CHUNK)->get()->all();

        return array_values(array_map(
            /** @param object $row */
            static fn (object $row): array => (array) $row,
            $rows,
        ));
    }

    private function orphanQuery(ForeignKeyCandidate $candidate): Builder
    {
        return $this->db->table($candidate->table)
            ->whereNotNull($candidate->column)
            ->whereNotExists(function (Builder $query) use ($candidate): void {
                $query->selectRaw('1')
                    ->from($candidate->referencedTable)
                    ->whereColumn(
                        $candidate->referencedTable.'.'.$candidate->referencedColumn,
                        $candidate->table.'.'.$candidate->column,
                    );
            });
    }
}
