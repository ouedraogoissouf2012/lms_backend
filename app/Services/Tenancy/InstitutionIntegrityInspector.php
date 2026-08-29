<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * Inspecteur d'intégrité référentielle de `institution_id` (issue #583).
 *
 * Service en LECTURE SEULE, partagé par :
 *   - App\Console\Commands\AuditInstitutionOrphans (mesure préalable)
 *   - la migration ajoutant les FK ON DELETE RESTRICT (garde pré-vol + idempotence)
 *
 * ## Pourquoi le query builder brut (et pas Eloquent)
 *
 * On interroge `connection()->table()` : aucun global scope `institution` ni
 * scope `SoftDeletes` n'est appliqué. C'est délibéré — une clé étrangère
 * référence l'EXISTENCE PHYSIQUE d'une ligne `institutions`, indépendamment de
 * `deleted_at` (une institution soft-deletée conserve sa ligne, donc
 * n'orpheline personne). L'inspecteur observe donc exactement ce que la base
 * contraindra.
 *
 * ## DI strict (§1.6-D)
 *
 * `DatabaseManager` injecté (résolu comme `db`), aucune facade. Les tables à
 * inspecter sont PASSÉES en argument (jamais lues depuis la config par le
 * service) : le service reste pur et testable avec n'importe quel sous-ensemble.
 *
 * @see config/tenancy.php (liste des tables — source unique de vérité)
 */
final class InstitutionIntegrityInspector implements InstitutionIntegrityInspectorInterface
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /**
     * Filtre les tables réellement présentes ET portant `institution_id`.
     * Robustesse cross-environnement : une table déclarée mais absente (ou sans
     * la colonne) est ignorée sans erreur.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function scopedTablesPresent(array $tables): array
    {
        $schema = $this->db->connection()->getSchemaBuilder();

        return array_values(array_filter(
            $tables,
            static fn (string $table): bool => $schema->hasTable($table)
                && $schema->hasColumn($table, 'institution_id'),
        ));
    }

    /**
     * Nombre de lignes à `institution_id` NULL (légitimes pour comptes
     * plateforme, mais utiles à la mesure).
     */
    public function nullCount(string $table): int
    {
        return $this->db->connection()->table($table)
            ->whereNull('institution_id')
            ->count();
    }

    /**
     * Nombre de lignes ORPHELINES : `institution_id` non nul ne correspondant à
     * aucune institution existante.
     */
    public function orphanCount(string $table): int
    {
        return $this->db->connection()->table($table)
            ->whereNotNull($table.'.institution_id')
            ->whereNotExists(fn (Builder $q): Builder => $q->from('institutions')
                ->whereColumn('institutions.id', $table.'.institution_id'))
            ->count();
    }

    /**
     * Rapport de mesure complet.
     *
     * @param  list<string>  $tables
     * @return array<string, array{null: int, orphan: int}>
     */
    public function report(array $tables): array
    {
        $report = [];
        foreach ($this->scopedTablesPresent($tables) as $table) {
            $report[$table] = [
                'null' => $this->nullCount($table),
                'orphan' => $this->orphanCount($table),
            ];
        }

        return $report;
    }

    /**
     * Tables présentant au moins une ligne orpheline (garde pré-vol de la
     * migration). Clé = table, valeur = nombre d'orphelins.
     *
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    public function orphans(array $tables): array
    {
        $offenders = [];
        foreach ($this->scopedTablesPresent($tables) as $table) {
            $count = $this->orphanCount($table);
            if ($count > 0) {
                $offenders[$table] = $count;
            }
        }

        return $offenders;
    }

    /**
     * La table porte-t-elle déjà une FK sur `institution_id` ? Via l'API native
     * `Schema::getForeignKeys()` (Laravel 11+, cross-engine MySQL/SQLite).
     * Sert à l'idempotence de la migration (ne pas recréer une FK existante).
     */
    public function hasInstitutionForeignKey(string $table): bool
    {
        foreach ($this->db->connection()->getSchemaBuilder()->getForeignKeys($table) as $foreignKey) {
            /** @var array{columns: list<string>} $foreignKey */
            if (in_array('institution_id', $foreignKey['columns'], true)) {
                return true;
            }
        }

        return false;
    }
}
