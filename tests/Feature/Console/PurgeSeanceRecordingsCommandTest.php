<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SeanceRecordingStatus;
use App\Models\AuditLog;
use App\Models\Chapter;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\SeanceRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class PurgeSeanceRecordingsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-12 12:00:00');
        config(['recordings.retention_days' => 365]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_requires_an_explicit_mode(): void
    {
        $this->artisan('recordings:purge')->assertExitCode(2);
        $this->artisan('recordings:purge --dry-run --apply')->assertExitCode(2);
    }

    public function test_dry_run_reports_without_deleting_anything(): void
    {
        [$recording, $chapter] = $this->expiredRecordingWithChapter();

        self::assertSame(0, Artisan::call('recordings:purge', ['--dry-run' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('"eligible":1', $output);
        self::assertStringContainsString('"purged":0', $output);

        self::assertTrue($recording->fresh()?->exists);
        self::assertTrue($chapter->fresh()?->exists);
    }

    public function test_apply_purges_expired_metadata_and_owned_chapter_idempotently(): void
    {
        [$recording, $chapter] = $this->expiredRecordingWithChapter();
        AuditLog::factory()->create([
            'auditable_type' => SeanceRecording::class,
            'auditable_id' => $recording->id,
        ]);

        self::assertSame(0, Artisan::call('recordings:purge', ['--apply' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('"purged":1', $output);
        self::assertStringContainsString('"chapters_purged":1', $output);
        self::assertStringContainsString('"provider_files_ignored":1', $output);

        self::assertSame(0, Artisan::call('recordings:purge', ['--apply' => true]));
        self::assertStringContainsString('"purged":0', Artisan::output());

        self::assertNull($recording->fresh());
        self::assertNull(Chapter::withTrashed()->find($chapter->id));
        self::assertSame(1, AuditLog::query()->where('auditable_id', $recording->id)->count());
    }

    public function test_cutoff_is_strict_and_active_recordings_are_ignored(): void
    {
        $seance = Seance::factory()->create();
        $atCutoff = $this->recording($seance, SeanceRecordingStatus::Ready, now()->subDays(365));
        $older = $this->recording($seance, SeanceRecordingStatus::Failed, now()->subDays(365)->subSecond());
        $active = $this->recording($seance, SeanceRecordingStatus::Recording, now()->subDays(400));

        $this->artisan('recordings:purge --apply')->assertSuccessful();

        self::assertNotNull($atCutoff->fresh());
        self::assertNull($older->fresh());
        self::assertNotNull($active->fresh());
    }

    public function test_chapter_from_another_tenant_is_never_deleted(): void
    {
        $owner = Institution::factory()->create();
        $other = Institution::factory()->create();
        $seance = Seance::factory()->forInstitution($owner)->create();
        $chapter = Chapter::factory()->forInstitution($other)->create([
            'notes_enseignant' => ['source' => 'visio_recording', 'seance_id' => $seance->id],
        ]);
        $recording = $this->recording($seance, SeanceRecordingStatus::Ready, now()->subDays(400), $chapter);

        $this->artisan('recordings:purge --apply')
            ->expectsOutputToContain('"chapters_purged":0')
            ->assertSuccessful();

        self::assertNull($recording->fresh());
        self::assertNotNull($chapter->fresh());
    }

    /** @return array{SeanceRecording, Chapter} */
    private function expiredRecordingWithChapter(): array
    {
        $institution = Institution::factory()->create();
        $seance = Seance::factory()->forInstitution($institution)->create();
        $chapter = Chapter::factory()->forInstitution($institution)->create([
            'notes_enseignant' => ['source' => 'visio_recording', 'seance_id' => $seance->id],
        ]);
        $recording = $this->recording($seance, SeanceRecordingStatus::Ready, now()->subDays(400), $chapter);

        return [$recording, $chapter];
    }

    private function recording(
        Seance $seance,
        SeanceRecordingStatus $status,
        Carbon $anchor,
        ?Chapter $chapter = null,
    ): SeanceRecording {
        return SeanceRecording::factory()->forSeance($seance)->create([
            'chapter_id' => $chapter?->id,
            'status' => $status,
            'provider_recording_id' => 'provider-'.$seance->id.'-'.$status->value,
            'recording_url' => 'https://provider.example.test/recording.mp4',
            'processed_at' => $status->isActive() ? null : $anchor,
            'started_at' => $status->isActive() ? $anchor : null,
            'created_at' => $anchor,
            'updated_at' => $anchor,
        ]);
    }
}
