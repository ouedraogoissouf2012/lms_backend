<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integrity;

use App\Services\Integrity\ForeignKeyCandidate;
use App\Services\Integrity\OrphanRowPurger;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

/**
 * Purge des lignes orphelines avant pose d'une clé étrangère (#541 — R3/R4).
 */
#[CoversClass(OrphanRowPurger::class)]
final class OrphanRowPurgerTest extends IntegrityProbeTestCase
{
    private const CANDIDATE_COLUMN = 'seance_id';

    protected function setUp(): void
    {
        parent::setUp();

        $schema = $this->db->connection()->getSchemaBuilder();
        $schema->create('probe_seances', static fn (Blueprint $t) => $t->id());
        $schema->create('probe_attendance', static function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('seance_id')->nullable();
            $t->string('nom')->nullable();
        });

        $this->db->table('probe_seances')->insert([['id' => 1], ['id' => 2]]);
        $this->db->table('probe_attendance')->insert([
            ['id' => 10, 'seance_id' => 1, 'nom' => 'ok-1'],
            ['id' => 11, 'seance_id' => 404, 'nom' => 'orphelin-1'],
            ['id' => 12, 'seance_id' => 2, 'nom' => 'ok-2'],
            ['id' => 13, 'seance_id' => 999, 'nom' => 'orphelin-2'],
            ['id' => 14, 'seance_id' => null, 'nom' => 'non-renseigne'],
        ]);
    }

    public function test_removes_only_the_orphan_rows(): void
    {
        $purged = $this->purger()->purge($this->candidate());

        self::assertSame(2, $purged);
        self::assertSame(
            [10, 12, 14],
            $this->db->table('probe_attendance')->orderBy('id')->pluck('id')->map(intval(...))->all(),
            'Les lignes référençant une séance existante — et celles à NULL — doivent survivre.',
        );
    }

    public function test_archives_each_orphan_before_deleting_it(): void
    {
        $this->purger()->purge($this->candidate());

        $archived = $this->archived();
        self::assertCount(2, $archived);
        self::assertSame([11, 13], array_map(static fn (array $r): int => (int) $r['source_row_id'], $archived));
        self::assertSame('probe_attendance', $archived[0]['source_table']);
        self::assertSame('fk:probe_attendance.seance_id', $archived[0]['reason']);

        $payload = json_decode((string) $archived[0]['payload'], true);
        self::assertIsArray($payload);
        self::assertSame('orphelin-1', $payload['nom'], 'La ligne archivée doit être récupérable intégralement.');
    }

    public function test_is_a_no_op_on_healthy_data(): void
    {
        $this->purger()->purge($this->candidate());
        $secondRun = $this->purger()->purge($this->candidate());

        self::assertSame(0, $secondRun);
        self::assertCount(2, $this->archived(), 'Une relance ne doit rien ajouter à la quarantaine.');
    }

    public function test_null_foreign_key_is_never_considered_orphan(): void
    {
        $this->db->table('probe_attendance')->where('id', '>', 10)->delete();
        $this->db->table('probe_attendance')->insert([['id' => 20, 'seance_id' => null, 'nom' => 'nullable']]);

        self::assertSame(0, $this->purger()->purge($this->candidate()));
        self::assertSame(2, $this->db->table('probe_attendance')->count());
    }

    private function candidate(): ForeignKeyCandidate
    {
        return new ForeignKeyCandidate('probe_attendance', self::CANDIDATE_COLUMN, 'probe_seances');
    }

    private function purger(): OrphanRowPurger
    {
        return new OrphanRowPurger($this->db, $this->quarantine(), new NullLogger());
    }
}
