<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Tenancy;

use App\Services\Tenancy\InstitutionIntegrityInspector;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Test unitaire ISOLÉ de l'inspecteur (#583).
 *
 * On monte une connexion SQLite en mémoire dédiée, **FK désactivées**, avec un
 * schéma minimal — ce qui permet de semer librement des lignes orphelines
 * (impossible dans une table déjà contrainte) et teste la logique de comptage
 * en isolation, sans dépendre du jeu complet de migrations de l'application.
 */
#[CoversClass(InstitutionIntegrityInspector::class)]
final class InstitutionIntegrityInspectorTest extends TestCase
{
    private DatabaseManager $db;

    private InstitutionIntegrityInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.tenancy_probe' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => false,
        ]]);

        $this->db = app(DatabaseManager::class);
        $this->db->setDefaultConnection('tenancy_probe');

        $schema = $this->db->connection()->getSchemaBuilder();
        $schema->create('institutions', static fn (Blueprint $t) => $t->id());
        $schema->create('lessons', static function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('institution_id')->nullable();
        });
        // Table portant DÉJÀ une FK sur institution_id.
        $schema->create('files', static function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('institution_id')->nullable();
            $t->foreign('institution_id')->references('id')->on('institutions');
        });
        // Table SANS colonne institution_id (doit être filtrée).
        $schema->create('unrelated', static fn (Blueprint $t) => $t->id());

        $this->db->connection()->table('institutions')->insert(['id' => 1]);

        $this->inspector = new InstitutionIntegrityInspector($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->setDefaultConnection('sqlite');

        parent::tearDown();
    }

    public function test_scoped_tables_present_filters_missing_table_and_missing_column(): void
    {
        $present = $this->inspector->scopedTablesPresent(['lessons', 'files', 'unrelated', 'ghost_table']);

        self::assertSame(['lessons', 'files'], $present);
    }

    public function test_null_count_counts_only_null_institution_ids(): void
    {
        $this->seedLessons();

        self::assertSame(2, $this->inspector->nullCount('lessons'));
    }

    public function test_orphan_count_counts_only_non_null_without_matching_institution(): void
    {
        $this->seedLessons();

        // 1 valide (inst 1), 2 NULL, 1 orphelin (inst 999) → 1 orphelin.
        self::assertSame(1, $this->inspector->orphanCount('lessons'));
    }

    public function test_report_returns_null_and_orphan_counts_per_present_table(): void
    {
        $this->seedLessons();

        $report = $this->inspector->report(['lessons', 'files', 'ghost_table']);

        self::assertSame([
            'lessons' => ['null' => 2, 'orphan' => 1],
            'files' => ['null' => 0, 'orphan' => 0],
        ], $report);
    }

    public function test_orphans_returns_only_offending_tables(): void
    {
        $this->seedLessons();

        self::assertSame(['lessons' => 1], $this->inspector->orphans(['lessons', 'files']));
    }

    public function test_orphans_is_empty_when_no_orphan(): void
    {
        $this->db->connection()->table('lessons')->insert(['institution_id' => 1]);

        self::assertSame([], $this->inspector->orphans(['lessons', 'files']));
    }

    public function test_has_institution_foreign_key_detects_presence_and_absence(): void
    {
        self::assertTrue($this->inspector->hasInstitutionForeignKey('files'));
        self::assertFalse($this->inspector->hasInstitutionForeignKey('lessons'));
    }

    private function seedLessons(): void
    {
        $this->db->connection()->table('lessons')->insert([
            ['institution_id' => 1],    // valide
            ['institution_id' => null], // NULL
            ['institution_id' => null], // NULL
            ['institution_id' => 999],  // orphelin
        ]);
    }
}
