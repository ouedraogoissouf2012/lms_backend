<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\SeanceRecordingStatus;
use App\Jobs\ImportJibriRecordingMedia;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\SeanceRecording;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * #708 — un crash de stockage laisse l'enregistrement Failed avec motif.
 */
final class ImportJibriRecordingMediaFailedTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_failed_marks_recording_failed_with_stable_reason(): void
    {
        $institution = Institution::factory()->create();
        app(TenantManager::class)->set($institution);
        $seance = Seance::factory()->forInstitution($institution)->create();
        $recording = SeanceRecording::factory()->forSeance($seance)->create([
            'status' => SeanceRecordingStatus::Processing,
        ]);

        $job = new ImportJibriRecordingMedia($recording->id, 'session-708');
        $job->failed(new RuntimeException('disk full'));

        $fresh = $recording->fresh();
        self::assertSame(SeanceRecordingStatus::Failed, $fresh->status);
        self::assertSame('storage_exception', $fresh->error_message);
    }
}
