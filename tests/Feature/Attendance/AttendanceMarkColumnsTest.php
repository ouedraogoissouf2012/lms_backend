<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceMark;
use App\Enums\AttendanceOrigin;
use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #717 — `status` visio et marque pédagogique sont indépendants.
 */
final class AttendanceMarkColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_excused_does_not_overwrite_visio_status(): void
    {
        $institution = Institution::factory()->create();
        $seance = Seance::factory()->create(['institution_id' => $institution->id]);
        $student = User::factory()->student()->create(['institution_id' => $institution->id]);
        $trainer = User::factory()->teacher()->create(['institution_id' => $institution->id]);

        $row = ESBTPAttendance::factory()->create([
            'institution_id' => $institution->id,
            'seance_id' => $seance->id,
            'user_id' => $student->id,
            'status' => 'connected',
            'mark' => AttendanceMark::Excused,
            'origin' => AttendanceOrigin::Delegated,
            'delegated_by_id' => $trainer->id,
            'delegated_at' => now(),
        ]);

        $row->refresh();
        $this->assertSame('connected', $row->status);
        $this->assertSame(AttendanceMark::Excused, $row->mark);
        $this->assertSame(AttendanceOrigin::Delegated, $row->origin);
        $this->assertSame($trainer->id, $row->delegated_by_id);
    }

    public function test_reconnect_cannot_insert_a_second_row(): void
    {
        $institution = Institution::factory()->create();
        $seance = Seance::factory()->create(['institution_id' => $institution->id]);
        $student = User::factory()->student()->create(['institution_id' => $institution->id]);

        ESBTPAttendance::factory()->create([
            'institution_id' => $institution->id,
            'seance_id' => $seance->id,
            'user_id' => $student->id,
            'status' => 'disconnected',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        ESBTPAttendance::factory()->create([
            'institution_id' => $institution->id,
            'seance_id' => $seance->id,
            'user_id' => $student->id,
            'status' => 'connected',
        ]);
    }
}
