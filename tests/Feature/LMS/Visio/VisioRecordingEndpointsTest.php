<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Enums\SeanceRecordingStatus;
use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\SeanceRecording;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class VisioRecordingEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $teacher;

    private Seance $seance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Cache::flush();

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->teacher()->for($this->institution)->create([
            'klassci_id' => 9001,
            'klassci_enseignant_id' => 9001,
        ]);
        $this->seance = Seance::factory()->forInstitution($this->institution)->visioActive()->create([
            'klassci_enseignant_id' => 9001,
            'klassci_classe_id' => 44,
        ]);
    }

    public function test_owner_teacher_can_start_stop_and_poll_recording_idempotently(): void
    {
        Sanctum::actingAs($this->teacher);

        $start = $this->postJson($this->recordingUrl('start'));
        $start->assertOk()
            ->assertJsonPath('data.recording.status', 'recording')
            ->assertJsonPath('data.recording.url', null)
            ->assertJsonPath('data.recording.is_recording', true)
            ->assertJsonPath('data.recording.consent_required', true)
            ->assertJsonPath('data.recording.can_download', false);

        $recordingId = $start->json('data.recording.id');
        $this->postJson($this->recordingUrl('start'))
            ->assertOk()
            ->assertJsonPath('data.recording.id', $recordingId)
            ->assertJsonPath('data.recording.status', 'recording');

        $this->getJson($this->recordingUrl())
            ->assertOk()
            ->assertJsonPath('data.recording.status', 'recording')
            ->assertJsonPath('data.recording.retention_days', 365);

        $stop = $this->postJson($this->recordingUrl('stop'));
        $stop->assertOk()
            ->assertJsonPath('data.recording.id', $recordingId)
            ->assertJsonPath('data.recording.status', 'processing');
        $this->assertNotNull($stop->json('data.recording.stopped_at'));

        $this->postJson($this->recordingUrl('stop'))
            ->assertOk()
            ->assertJsonPath('data.recording.status', 'processing');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'visio_recording_start',
            'auditable_id' => (int) $recordingId,
            'user_id' => $this->teacher->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'visio_recording_read',
            'auditable_id' => (int) $recordingId,
            'user_id' => $this->teacher->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'visio_recording_stop',
            'auditable_id' => (int) $recordingId,
            'user_id' => $this->teacher->id,
        ]);
    }

    public function test_concurrent_start_collision_returns_existing_recording(): void
    {
        $injected = false;
        SeanceRecording::creating(function (SeanceRecording $candidate) use (&$injected): void {
            if ($injected || (int) $candidate->seance_id !== $this->seance->id) {
                return;
            }

            $injected = true;
            SeanceRecording::withoutEvents(fn () => SeanceRecording::query()->create([
                'seance_id' => $this->seance->id,
                'status' => SeanceRecordingStatus::Recording,
                'started_at' => now(),
                'active_lock_key' => SeanceRecording::activeLockKeyForSeance($this->seance->id),
            ]));
        });
        Sanctum::actingAs($this->teacher);

        $this->postJson($this->recordingUrl('start'))
            ->assertOk()
            ->assertJsonPath('data.recording.status', 'recording');

        self::assertSame(1, SeanceRecording::query()->where('seance_id', $this->seance->id)->count());
    }

    public function test_non_owner_teacher_cannot_control_recording(): void
    {
        $otherTeacher = User::factory()->teacher()->for($this->institution)->create([
            'klassci_id' => 1234,
            'klassci_enseignant_id' => 1234,
        ]);
        Sanctum::actingAs($otherTeacher);

        $this->postJson($this->recordingUrl('start'))->assertStatus(403);
        $this->postJson($this->recordingUrl('stop'))->assertStatus(403);
    }

    public function test_student_in_seance_class_can_read_status_but_cannot_start(): void
    {
        $student = User::factory()->student()->for($this->institution)->create();
        UserClass::create([
            'user_id' => $student->id,
            'klassci_classe_id' => 44,
            'classe_nom' => 'Classe 44',
            'institution_id' => $this->institution->id,
        ]);
        Sanctum::actingAs($student);

        $this->getJson($this->recordingUrl())
            ->assertOk()
            ->assertJsonPath('data.recording.status', 'idle')
            ->assertJsonPath('data.recording.consent_required', false)
            ->assertJsonPath('data.recording.consent_message', fn ($message): bool => is_string($message) && $message !== '');

        $this->postJson($this->recordingUrl('start'))->assertStatus(403);
    }

    public function test_joined_participant_can_read_status(): void
    {
        $student = User::factory()->student()->for($this->institution)->create();
        ESBTPAttendance::factory()->for($this->seance)->for($student, 'user')->create([
            'institution_id' => $this->institution->id,
            'status' => 'connected',
        ]);
        Sanctum::actingAs($student);

        $this->getJson($this->recordingUrl())->assertOk();
    }

    private function recordingUrl(?string $action = null): string
    {
        $base = "/api/lms/seances/{$this->seance->klassci_seance_id}/recording";

        return $action === null ? $base : "{$base}/{$action}";
    }
}
