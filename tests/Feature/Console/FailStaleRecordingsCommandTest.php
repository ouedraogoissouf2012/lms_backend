<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SeanceRecordingStatus;
use App\Models\SeanceRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #514 — filet de sécurité. Sans webhook provider (cf. #204), un
 * enregistrement arrêté reste bloqué en `Processing` indéfiniment (le finaliseur
 * ProcessSeanceRecordingReady n'est jamais dispatché). La commande
 * `recordings:fail-stale-processing` le passe à `Failed` au-delà du délai
 * (`config('recordings.stale_processing_minutes')`), ce qui reflète un état
 * honnête ET libère le verrou actif (un nouvel enregistrement redevient possible).
 *
 * @see app/Console/Commands/FailStaleRecordings.php
 * @see app/Services/Visio/Recording/StaleRecordingFailer.php
 */
final class FailStaleRecordingsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['recordings.stale_processing_minutes' => 30]);
    }

    public function test_fails_recording_stuck_in_processing_beyond_threshold(): void
    {
        $stale = SeanceRecording::factory()->processing()->create([
            'stopped_at' => now()->subMinutes(45),
        ]);
        self::assertNotNull($stale->active_lock_key, 'le verrou est posé tant que le statut est actif');

        $this->artisan('recordings:fail-stale-processing')->assertSuccessful();

        $stale->refresh();
        self::assertSame(SeanceRecordingStatus::Failed, $stale->status);
        self::assertNotNull($stale->error_message);
        self::assertNotNull($stale->processed_at);
        self::assertNull($stale->active_lock_key, 'le verrou actif est libéré → nouvel enregistrement possible');
    }

    public function test_leaves_recently_stopped_processing_untouched(): void
    {
        $recent = SeanceRecording::factory()->processing()->create([
            'stopped_at' => now()->subMinutes(5),
        ]);

        $this->artisan('recordings:fail-stale-processing')->assertSuccessful();

        self::assertSame(SeanceRecordingStatus::Processing, $recent->refresh()->status);
    }

    public function test_leaves_active_recording_and_ready_untouched(): void
    {
        $recording = SeanceRecording::factory()->recording()->create();
        $ready = SeanceRecording::factory()->ready()->create([
            'stopped_at' => now()->subDay(),
        ]);

        $this->artisan('recordings:fail-stale-processing')->assertSuccessful();

        self::assertSame(SeanceRecordingStatus::Recording, $recording->refresh()->status);
        self::assertSame(SeanceRecordingStatus::Ready, $ready->refresh()->status);
    }

    public function test_respects_configurable_threshold(): void
    {
        config(['recordings.stale_processing_minutes' => 120]);

        $stoppedNinetyMinAgo = SeanceRecording::factory()->processing()->create([
            'stopped_at' => now()->subMinutes(90),
        ]);

        // 90 min < seuil de 120 min → doit rester Processing.
        $this->artisan('recordings:fail-stale-processing')->assertSuccessful();

        self::assertSame(SeanceRecordingStatus::Processing, $stoppedNinetyMinAgo->refresh()->status);
    }
}
