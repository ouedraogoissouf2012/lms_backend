<?php

declare(strict_types=1);

namespace App\Services\Integrity;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Psr\Log\LoggerInterface;

/**
 * Retire les doublons qui empêchent la pose d'un index unique (#541 — R1/R2).
 *
 * Une seule responsabilité — « retirer les doublons d'une clé sans perte de
 * données » — déclinée selon ce que la table permet :
 *
 *   - table soft-deletable → horodatage de la colonne de suppression ;
 *   - table sans soft delete (pivots) → suppression physique.
 *
 * **Dans les DEUX cas la ligne est d'abord archivée intégralement** dans
 * `orphan_row_archive`. Le soft delete ne suffit pas à lui seul à garantir la
 * réversibilité : une fois l'index unique posé, ré-annuler le `deleted_at`
 * d'une ligne retirée le VIOLE (la survivante occupe déjà la clé). La copie
 * d'archive est donc le seul chemin de récupération réel — restaurer suppose
 * de libérer d'abord la clé côté survivante.
 *
 * **Ligne conservée** : décidée par une {@see DuplicateSurvivorPolicy} choisie
 * table par table. Garder aveuglément la plus ancienne retirerait l'évaluation
 * qui porte les copies notées, ou l'inscription active au profit d'une
 * abandonnée — voir les politiques concrètes.
 *
 * **Colonnes NULL** : un groupe dont une colonne de clé est `NULL` n'est PAS un
 * doublon — SQL exclut les `NULL` d'un index unique. On les ignore donc, sinon
 * on retirerait des lignes que la contrainte n'aurait jamais rejetées.
 */
final class DuplicateRowRetirer
{
    /**
     * Nombre de groupes dupliqués traités par passe. Public : le test de
     * régression de la pagination doit pouvoir en semer un de plus sans coder
     * la valeur en dur des deux côtés.
     */
    public const GROUP_CHUNK = 200;

    /** Borne la taille des `IN (...)` et la mémoire, comme les classes sœurs. */
    private const ROW_CHUNK = 500;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly RowQuarantine $quarantine,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param  list<string>  $keyColumns  Colonnes de l'index unique visé.
     * @param  string|null  $softDeleteColumn  Colonne de soft delete, si la table en a une.
     * @return int Nombre de lignes retirées.
     */
    public function retire(
        string $table,
        array $keyColumns,
        DuplicateSurvivorPolicy $survivor,
        ?string $softDeleteColumn = null,
    ): int {
        $retired = 0;

        // Boucle jusqu'à épuisement : `duplicatedGroups()` est PAGINÉE (un index
        // unique refuserait de se poser s'il restait ne serait-ce qu'un groupe non
        // traité au-delà du premier lot). Chaque passe retire au moins une ligne
        // par groupe, donc la boucle converge ; le garde `$pass === 0` interdit la
        // boucle infinie si une ligne résistait au retrait.
        while (($groups = $this->duplicatedGroups($table, $keyColumns, $softDeleteColumn)) !== []) {
            $pass = 0;

            foreach ($groups as $group) {
                $pass += $this->retireGroup($table, $keyColumns, $softDeleteColumn, $group, $survivor);
            }

            if ($pass === 0) {
                $this->logger->error('Doublons impossibles à retirer — arrêt pour éviter une boucle infinie', [
                    'table' => $table,
                    'key' => $keyColumns,
                    'groupes_restants' => count($groups),
                ]);

                break;
            }

            $retired += $pass;
        }

        if ($retired > 0) {
            $this->logger->warning('Doublons retirés avant pose d\'un index unique', [
                'table' => $table,
                'key' => $keyColumns,
                'mode' => $softDeleteColumn !== null ? 'soft-delete' : 'suppression',
                'politique' => $survivor::class,
                'retired' => $retired,
            ]);
        }

        return $retired;
    }

    /**
     * @param  list<string>  $keyColumns
     * @return list<array<string, mixed>> Valeurs de clé des groupes en doublon.
     */
    private function duplicatedGroups(string $table, array $keyColumns, ?string $softDeleteColumn): array
    {
        $query = $this->liveRows($table, $softDeleteColumn)
            ->select($keyColumns)
            ->groupBy($keyColumns)
            ->havingRaw('COUNT(*) > 1')
            ->limit(self::GROUP_CHUNK);

        foreach ($keyColumns as $column) {
            $query->whereNotNull($column);
        }

        return self::asRows($query->get()->all());
    }

    /**
     * Une requête par groupe : MySQL interdit de lire la table en sous-requête
     * d'un UPDATE/DELETE la visant (erreur 1093), ce qui exclut la passe unique.
     * Acceptable ici — code de migration exécuté une seule fois, sur un nombre de
     * groupes qui est par nature l'anomalie, pas le volume.
     *
     * @param  list<string>  $keyColumns
     * @param  array<string, mixed>  $group
     */
    private function retireGroup(
        string $table,
        array $keyColumns,
        ?string $softDeleteColumn,
        array $group,
        DuplicateSurvivorPolicy $survivor,
    ): int {
        $query = $this->liveRows($table, $softDeleteColumn);

        foreach ($keyColumns as $column) {
            $query->where($column, $group[$column]);
        }

        $rows = self::asRows($query->orderBy('id')->get()->all());

        if (count($rows) < 2) {
            return 0;
        }

        $survivorId = $survivor->survivorId($table, $rows);
        $doomed = array_values(array_filter(
            $rows,
            static fn (array $row): bool => RowIdentifier::of($row) !== $survivorId,
        ));

        $reason = $this->reason($table, $keyColumns);
        $retired = 0;

        foreach (array_chunk($doomed, self::ROW_CHUNK) as $chunk) {
            $retired += $softDeleteColumn !== null
                ? $this->softDelete($table, $chunk, $softDeleteColumn, $reason)
                : $this->quarantine->purge($table, $chunk, $reason);
        }

        return $retired;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function softDelete(string $table, array $rows, string $softDeleteColumn, string $reason): int
    {
        // Archive AVANT : une fois l'index unique posé, annuler le soft delete le
        // violerait — l'archive est le seul chemin de récupération réel.
        $this->quarantine->archive($table, $rows, $reason);

        return $this->db->table($table)
            ->whereIn('id', RowIdentifier::allOf($rows))
            ->update([$softDeleteColumn => now()]);
    }

    private function liveRows(string $table, ?string $softDeleteColumn): Builder
    {
        $query = $this->db->table($table);

        return $softDeleteColumn !== null ? $query->whereNull($softDeleteColumn) : $query;
    }

    /**
     * @param  array<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private static function asRows(array $rows): array
    {
        return array_values(array_map(static fn (object $row): array => (array) $row, $rows));
    }

    /**
     * @param  list<string>  $keyColumns
     */
    private function reason(string $table, array $keyColumns): string
    {
        return 'duplicate:'.$table.'('.implode(',', $keyColumns).')';
    }
}
