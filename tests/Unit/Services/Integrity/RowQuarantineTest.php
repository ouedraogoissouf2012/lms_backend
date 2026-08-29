<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integrity;

use App\Services\Integrity\ArchivedRowWriterInterface;
use App\Services\Integrity\RowQuarantine;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * Le point de non-retour de #541 : aucune ligne n'est retirée de sa table tant
 * que sa copie d'archive n'a pas été RELUE.
 *
 * L'écriture d'archive passe par `insertOrIgnore`, nécessaire à l'idempotence
 * mais qui dégrade les erreurs en avertissements sous MySQL (`INSERT IGNORE`) :
 * une copie peut donc manquer sans qu'aucune exception ne soit levée. C'est
 * exactement ce que simule l'écrivain défaillant substitué ici — et la raison
 * d'être de {@see ArchivedRowWriterInterface}.
 */
#[CoversClass(RowQuarantine::class)]
final class RowQuarantineTest extends IntegrityProbeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->db->connection()->getSchemaBuilder()->create('probe_rows', static function (Blueprint $t): void {
            $t->id();
            $t->string('libelle');
        });

        $this->db->table('probe_rows')->insert([
            ['id' => 1, 'libelle' => 'a'],
            ['id' => 2, 'libelle' => 'b'],
        ]);
    }

    public function test_rows_are_archived_then_deleted(): void
    {
        $deleted = $this->quarantine()->purge('probe_rows', $this->rows(), 'test:purge');

        self::assertSame(2, $deleted);
        self::assertSame(0, $this->db->table('probe_rows')->count());
        self::assertCount(2, $this->archived());
    }

    public function test_nothing_is_deleted_when_the_archive_is_incomplete(): void
    {
        $quarantine = new RowQuarantine($this->db, $this->silentlyFailingWriter());

        try {
            $quarantine->purge('probe_rows', $this->rows(), 'test:purge');
            self::fail('Une RuntimeException était attendue : la quarantaine est incomplète.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('probe_rows', $e->getMessage());
            self::assertStringContainsString('0/2', $e->getMessage());
        }

        self::assertSame(
            2,
            $this->db->table('probe_rows')->count(),
            'Sans copie d’archive vérifiée, la suppression ne doit PAS avoir lieu.',
        );
    }

    public function test_archiving_nothing_is_harmless(): void
    {
        self::assertSame(0, $this->quarantine()->purge('probe_rows', [], 'test:purge'));
        self::assertSame(2, $this->db->table('probe_rows')->count());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return array_values(array_map(
            static fn (object $row): array => (array) $row,
            $this->db->table('probe_rows')->orderBy('id')->get()->all(),
        ));
    }

    /**
     * Écrivain qui prétend avoir écrit sans rien écrire — comportement exact d'un
     * `INSERT IGNORE` dont la ligne a été refusée (payload trop long, jeu de
     * caractères, contrainte) : aucune exception, aucune ligne.
     */
    private function silentlyFailingWriter(): ArchivedRowWriterInterface
    {
        return new class implements ArchivedRowWriterInterface
        {
            public function write(string $sourceTable, iterable $rows, string $reason): int
            {
                return 0;
            }
        };
    }
}
