<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Visio\Recording;

use App\Models\Chapter;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\Seance;
use App\Models\User;
use App\Services\TenantManager;
use App\Services\Visio\Recording\SeanceRecordingAttachmentGuard;
use App\Services\Visio\Recording\SeanceRecordingAttachmentResolver;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\NullLogger;
use Tests\TestCase;

final class SeanceRecordingAttachmentResolverTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->app->make(TenantManager::class)->set($this->institution);
    }

    public function test_ready_recording_is_attached_to_matching_lesson_as_video_chapter(): void
    {
        [$seance, $lesson] = $this->makeResolvableSeanceAndLesson();

        $result = $this->resolver()->attachReadyRecording(
            $seance,
            'https://cdn.example.test/recordings/seance-501.mp4',
            provider: 's3',
        );

        self::assertTrue($result->success);
        self::assertTrue($result->lesson?->is($lesson));
        self::assertSame('attached', $result->reason);

        $chapter = $result->chapter;
        self::assertInstanceOf(Chapter::class, $chapter);
        self::assertSame($lesson->id, $chapter->lesson_id);
        self::assertSame('video', $chapter->content_type);
        self::assertSame('s3', $chapter->video_provider);
        self::assertSame('https://cdn.example.test/recordings/seance-501.mp4', $chapter->video_url);
        self::assertSame('visio_recording', $chapter->notes_enseignant['source']);
        self::assertSame($seance->id, $chapter->notes_enseignant['seance_id']);
    }

    public function test_ambiguous_matching_lessons_fail_without_creating_chapter(): void
    {
        [$seance, $lesson] = $this->makeResolvableSeanceAndLesson();
        Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'matiere_id' => $lesson->matiere_id,
            'classe_id' => $lesson->classe_id,
            'enseignant_id' => $lesson->enseignant_id,
        ]);

        $result = $this->resolver()->attachReadyRecording($seance, 'https://cdn.example.test/a.mp4');

        self::assertFalse($result->success);
        self::assertSame('ambiguous_lesson', $result->reason);
        self::assertSame(0, Chapter::query()->where('content_type', 'video')->count());
        self::assertCount(2, $result->context['lesson_ids']);
    }

    public function test_missing_klassci_matiere_fails_without_losing_recording_url(): void
    {
        $seance = Seance::factory()->forInstitution($this->institution)->create([
            'klassci_matiere_id' => null,
        ]);

        $result = $this->resolver()->attachReadyRecording($seance, 'https://cdn.example.test/orphan.mp4');

        self::assertFalse($result->success);
        self::assertSame('missing_klassci_matiere_id', $result->reason);
        self::assertSame('https://cdn.example.test/orphan.mp4', $result->context['recording_url']);
        self::assertSame(0, Chapter::query()->where('content_type', 'video')->count());
    }

    public function test_second_attachment_updates_existing_recording_chapter(): void
    {
        [$seance] = $this->makeResolvableSeanceAndLesson();

        $first = $this->resolver()->attachReadyRecording($seance, 'https://cdn.example.test/old.mp4');
        $second = $this->resolver()->attachReadyRecording($seance, 'https://cdn.example.test/new.mp4');

        self::assertTrue($first->success);
        self::assertTrue($second->success);
        self::assertSame($first->chapter?->id, $second->chapter?->id);
        self::assertSame('https://cdn.example.test/new.mp4', $second->chapter?->video_url);
        self::assertSame(1, Chapter::query()->where('content_type', 'video')->count());
    }

    public function test_contended_attachment_lock_creates_no_duplicate_chapter(): void
    {
        [$seance] = $this->makeResolvableSeanceAndLesson();
        $store = app(CacheFactory::class)->store()->getStore();
        self::assertInstanceOf(LockProvider::class, $store);
        $lock = $store->lock(SeanceRecordingAttachmentGuard::key($seance->id), 30);
        self::assertTrue($lock->get());

        try {
            $this->resolver()->attachReadyRecording($seance, 'https://cdn.example.test/race.mp4');
            self::fail('A concurrent attachment must be retried.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Seance recording attachment is already locked.', $exception->getMessage());
            self::assertSame(0, Chapter::query()->where('content_type', 'video')->count());
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{Seance, Lesson}
     */
    private function makeResolvableSeanceAndLesson(): array
    {
        $matiere = Matiere::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 701,
        ]);
        $classe = Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 31,
        ]);
        $teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
            'klassci_enseignant_id' => 9001,
        ]);
        $lesson = Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'matiere_id' => $matiere->id,
            'classe_id' => $classe->id,
            'enseignant_id' => $teacher->id,
        ]);
        $seance = Seance::factory()->forInstitution($this->institution)->create([
            'klassci_matiere_id' => 701,
            'klassci_classe_id' => 31,
            'klassci_enseignant_id' => 9001,
            'klassci_seance_id' => 501,
            'titre' => 'Physique live',
        ]);

        return [$seance, $lesson];
    }

    private function resolver(): SeanceRecordingAttachmentResolver
    {
        return new SeanceRecordingAttachmentResolver(
            new NullLogger,
            app(SeanceRecordingAttachmentGuard::class),
        );
    }
}
