<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Enums\SeanceRecordingStatus;
use App\Jobs\ImportJibriRecordingMedia;
use App\Jobs\ProcessSeanceRecordingReady;
use App\Models\SeanceRecording;
use App\Services\Visio\Recording\RecordingMediaSource;
use App\Services\Visio\Recording\RecordingMediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * #469 — le LMS s'approprie le média, puis délègue le rattachement.
 *
 * ## Ce que ce job fait, et ce qu'il ne fait surtout pas
 *
 * Il localise, copie, et passe la main à {@see ProcessSeanceRecordingReady}, qui
 * existait avant #469 et **n'est pas modifié**. Rejouer la logique de
 * rattachement ici aurait dupliqué la résolution leçon/chapitre — c'est-à-dire
 * la partie la plus subtile du domaine, celle qui distingue `ambiguous_lesson`
 * de `lesson_not_found`.
 *
 * ## Le double en mémoire n'est pas une commodité
 *
 * `RecordingMediaSource` est remplacé par un double : c'est la seule façon de
 * prouver que le job ne dépend pas d'un disque réel — donc que le jour où le
 * nœud visio déménage sur une autre machine, seule l'implémentation change.
 *
 * @see \App\Services\Visio\Recording\RecordingMediaSource
 */
final class ImportJibriRecordingMediaTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = '00e7571b-7204-4ecb-8cab-7fb84b57b916';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(RecordingMediaStorage::DISK);
    }

    /**
     * Double de la source : renvoie un chemin fabriqué, ou `null` pour simuler
     * un média introuvable. Ne touche jamais au disque de production.
     */
    private function fakeSource(?string $path): void
    {
        $this->app->bind(RecordingMediaSource::class, fn (): RecordingMediaSource => new class($path) implements RecordingMediaSource
        {
            public function __construct(private readonly ?string $path)
            {
            }

            public function locate(string $sessionId): ?string
            {
                return $this->path;
            }
        });
    }

    private function existingMedia(string $contents = 'octets-video'): string
    {
        $path = sys_get_temp_dir().'/import-src-'.bin2hex(random_bytes(6)).'.mp4';
        file_put_contents($path, $contents);

        return $path;
    }

    private function activeRecording(): SeanceRecording
    {
        return SeanceRecording::factory()->processing()->create();
    }

    private function runImport(SeanceRecording $recording): void
    {
        $this->app->call([new ImportJibriRecordingMedia((int) $recording->id, self::SESSION), 'handle']);
    }

    // ------------------------------------------------------------ chemin nominal

    public function test_stores_the_media_and_hands_over_to_the_existing_job(): void
    {
        Queue::fake();
        $recording = $this->activeRecording();
        $this->fakeSource($this->existingMedia());

        $this->runImport($recording);

        Queue::assertPushedOn('low', ProcessSeanceRecordingReady::class);
    }

    public function test_records_the_provider_and_the_session_identifier(): void
    {
        Queue::fake();
        $recording = $this->activeRecording();
        $this->fakeSource($this->existingMedia());

        $this->runImport($recording);

        $fresh = $recording->fresh();
        $this->assertSame('jibri', $fresh?->provider);
        $this->assertSame(self::SESSION, $fresh?->provider_recording_id);
    }

    /** La taille est mesurée sur le fichier, jamais annoncée par l'appelant. */
    public function test_measures_the_media_size_itself(): void
    {
        Queue::fake();
        $recording = $this->activeRecording();
        $this->fakeSource($this->existingMedia('douze-octets'));

        $this->runImport($recording);

        $this->assertSame(12, $recording->fresh()?->file_size_bytes);
    }

    public function test_the_media_lands_on_the_lms_disk(): void
    {
        Queue::fake();
        $recording = $this->activeRecording();
        $this->fakeSource($this->existingMedia());

        $this->runImport($recording);

        $this->assertNotEmpty(
            Storage::disk(RecordingMediaStorage::DISK)->files("recordings/{$recording->id}/video"),
        );
    }

    // -------------------------------------------------------------------- échecs

    public function test_marks_failed_with_a_stable_reason_when_the_media_is_missing(): void
    {
        Queue::fake();
        $recording = $this->activeRecording();
        $this->fakeSource(null);

        $this->runImport($recording);

        $fresh = $recording->fresh();
        $this->assertSame(SeanceRecordingStatus::Failed, $fresh?->status);
        $this->assertSame('media_not_found', $fresh?->error_message);
        Queue::assertNotPushed(ProcessSeanceRecordingReady::class);
    }

    public function test_marks_failed_when_the_copy_cannot_be_performed(): void
    {
        Queue::fake();
        $recording = $this->activeRecording();
        $this->fakeSource('/chemin/absent/media.mp4');

        $this->runImport($recording);

        $fresh = $recording->fresh();
        $this->assertSame(SeanceRecordingStatus::Failed, $fresh?->status);
        $this->assertSame('media_copy_failed', $fresh?->error_message);
        Queue::assertNotPushed(ProcessSeanceRecordingReady::class);
    }

    /**
     * Le motif d'échec doit rester une **clé stable**, jamais un message
     * d'exception : `error_message` finit dans la réponse API (§1.2).
     */
    public function test_failure_reasons_never_leak_technical_details(): void
    {
        Queue::fake();
        $recording = $this->activeRecording();
        $this->fakeSource('/chemin/absent/media.mp4');

        $this->runImport($recording);

        $message = (string) $recording->fresh()?->error_message;
        $this->assertMatchesRegularExpression('/^[a-z_]+$/', $message);
    }

    // --------------------------------------------------------------- idempotence

    /**
     * Une notification rejouée ne doit pas réécrire un cours déjà publié : un
     * enregistrement `Ready` est laissé strictement intact.
     */
    public function test_a_ready_recording_is_left_untouched(): void
    {
        Queue::fake();
        $recording = SeanceRecording::factory()->ready()->create([
            'recording_url' => 'https://exemple.test/deja-publie.mp4',
        ]);
        $this->fakeSource($this->existingMedia());

        $this->runImport($recording);

        $this->assertSame('https://exemple.test/deja-publie.mp4', $recording->fresh()?->recording_url);
        Queue::assertNotPushed(ProcessSeanceRecordingReady::class);
        $this->assertSame(
            [],
            Storage::disk(RecordingMediaStorage::DISK)->allFiles(),
            'un enregistrement déjà publié ne doit produire aucun fichier',
        );
    }

    public function test_an_unknown_recording_is_ignored_without_failing(): void
    {
        Queue::fake();
        $this->fakeSource($this->existingMedia());

        $this->app->call([new ImportJibriRecordingMedia(999_999, self::SESSION), 'handle']);

        Queue::assertNotPushed(ProcessSeanceRecordingReady::class);
    }
}
