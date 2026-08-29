<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integrity;

use App\Services\Integrity\ArchivedRowWriter;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Écrivain de quarantaine (#541 — R4 non-destruction).
 */
#[CoversClass(ArchivedRowWriter::class)]
final class ArchivedRowWriterTest extends IntegrityProbeTestCase
{
    public function test_archives_the_complete_row_as_json(): void
    {
        $written = $this->writer()->write('esbtp_attendance', [
            ['id' => 7, 'seance_id' => 3, 'user_id' => 99, 'nom' => 'Traoré'],
        ], 'fk:esbtp_attendance.user_id');

        self::assertSame(1, $written);

        $rows = $this->archived();
        self::assertCount(1, $rows);
        self::assertSame('esbtp_attendance', $rows[0]['source_table']);
        self::assertSame(7, (int) $rows[0]['source_row_id']);
        self::assertSame('fk:esbtp_attendance.user_id', $rows[0]['reason']);

        $payload = json_decode((string) $rows[0]['payload'], true);
        self::assertSame(
            ['id' => 7, 'seance_id' => 3, 'user_id' => 99, 'nom' => 'Traoré'],
            $payload,
            'Le payload doit contenir la ligne INTÉGRALE, pas seulement la clé.',
        );
    }

    public function test_is_idempotent_on_replay(): void
    {
        $row = [['id' => 7, 'user_id' => 99]];

        $this->writer()->write('esbtp_attendance', $row, 'fk:esbtp_attendance.user_id');
        $this->writer()->write('esbtp_attendance', $row, 'fk:esbtp_attendance.user_id');

        self::assertCount(
            1,
            $this->archived(),
            'Une migration relancée après échec partiel ne doit pas dupliquer la quarantaine.',
        );
    }

    public function test_same_row_archived_under_two_distinct_reasons_is_kept_twice(): void
    {
        $row = [['id' => 7, 'seance_id' => 3, 'user_id' => 99]];

        $this->writer()->write('esbtp_attendance', $row, 'fk:esbtp_attendance.user_id');
        $this->writer()->write('esbtp_attendance', $row, 'fk:esbtp_attendance.seance_id');

        self::assertCount(2, $this->archived());
    }

    public function test_writing_nothing_writes_nothing(): void
    {
        self::assertSame(0, $this->writer()->write('esbtp_attendance', [], 'fk:x'));
        self::assertSame([], $this->archived());
    }

    private function writer(): ArchivedRowWriter
    {
        return new ArchivedRowWriter($this->db);
    }
}
