<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integrity;

use App\Services\Integrity\ArchivedRowWriter;
use App\Services\Integrity\RowQuarantine;
use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

/**
 * Socle des tests unitaires des réparateurs d'intégrité (#541).
 *
 * Même principe que `InstitutionIntegrityInspectorTest` (#583) : une connexion
 * SQLite en mémoire dédiée, **clés étrangères désactivées**, sur laquelle on peut
 * semer librement des lignes orphelines ou des doublons — impossible dans les
 * tables réelles une fois celles-ci contraintes par les migrations de cette même
 * issue.
 *
 * La table de quarantaine n'est PAS redéclarée à la main : on exécute la
 * migration réelle sur la connexion sonde, ce qui interdit toute dérive entre le
 * schéma testé et le schéma livré.
 */
abstract class IntegrityProbeTestCase extends TestCase
{
    protected DatabaseManager $db;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.integrity_probe' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => false,
        ]]);

        $this->db = app(DatabaseManager::class);
        $this->db->setDefaultConnection('integrity_probe');

        $this->migrationFor('2026_08_23_100000_create_orphan_row_archive_table.php')->up();
    }

    /**
     * Charge une migration réelle du dépôt pour l'exécuter sur la sonde.
     */
    protected function migrationFor(string $filename): object
    {
        /** @var object $migration */
        $migration = require base_path('database/migrations/'.$filename);

        return $migration;
    }

    protected function quarantine(): RowQuarantine
    {
        return new RowQuarantine($this->db, new ArchivedRowWriter($this->db));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function archived(): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            $this->db->table('orphan_row_archive')->orderBy('id')->get()->all(),
        );
    }
}
