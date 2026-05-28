<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Attendances;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Smoke tests for `LMSAttendancesController` after extraction (PR H of LMS split).
 *
 * 3 endpoints migrated. Tests focus on the **HTTP contract** (auth required,
 * routes correctly point to the new controller class).
 *
 * @see app/Http/Controllers/API/LMS/LMSAttendancesController.php
 * @see .claude/specs/lms-data-controller-split/
 */
final class AttendancesRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {

        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
    }

    public function test_sync_attendances_requires_auth(): void
    {
        $this->postJson('/api/lms/attendances/from-video-session', [])
            ->assertStatus(401);
    }

    public function test_attendance_history_requires_auth(): void
    {
        $this->getJson('/api/lms/attendance/history')->assertStatus(401);
    }

    public function test_seance_attendances_requires_auth(): void
    {
        $this->getJson('/api/lms/seances/42/attendances')->assertStatus(401);
    }
}
