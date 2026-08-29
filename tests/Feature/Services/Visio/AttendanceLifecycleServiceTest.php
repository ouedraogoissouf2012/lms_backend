<?php

namespace Tests\Feature\Services\Visio;

use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Visio\AttendanceLifecycleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\NullLogger;
use Tests\TestCase;

class AttendanceLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_inactive_disconnect_uses_last_seen_as_left_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-11 12:00:00'));
        $seance = $this->createSeance();
        $student = User::factory()->student()->for($seance->institution)->create();
        $lastSeenAt = now()->subMinutes(7);

        $attendance = ESBTPAttendance::factory()->create([
            'seance_id' => $seance->id,
            'user_id' => $student->id,
            'institution_id' => $seance->institution_id,
            'joined_at' => now()->subMinutes(20),
            'last_seen_at' => $lastSeenAt,
            'status' => 'connected',
        ]);

        $count = $this->service()->disconnectInactive(3);

        $attendance->refresh();
        $this->assertSame(1, $count);
        $this->assertSame('disconnected', $attendance->status);
        $this->assertTrue($attendance->left_at?->equalTo($lastSeenAt));
        $this->assertSame(13, $attendance->duration_minutes);
    }

    public function test_inactive_disconnect_without_heartbeat_uses_joined_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-11 12:00:00'));
        $seance = $this->createSeance();
        $student = User::factory()->student()->for($seance->institution)->create();
        $joinedAt = now()->subMinutes(8);

        $attendance = ESBTPAttendance::factory()->create([
            'seance_id' => $seance->id,
            'user_id' => $student->id,
            'institution_id' => $seance->institution_id,
            'joined_at' => $joinedAt,
            'last_seen_at' => null,
            'status' => 'connected',
        ]);

        $count = $this->service()->disconnectInactive(3);

        $attendance->refresh();
        $this->assertSame(1, $count);
        $this->assertSame('disconnected', $attendance->status);
        $this->assertTrue($attendance->left_at?->equalTo($joinedAt));
        $this->assertSame(0, $attendance->duration_minutes);
    }

    private function createSeance(): Seance
    {
        $institution = Institution::factory()->create();

        return Seance::factory()
            ->forInstitution($institution)
            ->visioActive()
            ->create();
    }

    private function service(): AttendanceLifecycleService
    {
        return new AttendanceLifecycleService(new NullLogger);
    }
}
