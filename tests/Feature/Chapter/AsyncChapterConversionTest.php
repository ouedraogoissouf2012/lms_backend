<?php

declare(strict_types=1);

namespace Tests\Feature\Chapter;

use App\Jobs\ConvertChapterFile;
use App\Models\Chapter;
use App\Models\User;
use App\Services\Chapter\AsyncChapterConversionStore;
use App\Services\Chapter\ChapterFileUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

final class AsyncChapterConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        $this->disableKlassciMiddleware();
    }

    public function test_upload_async_accepts_conversion_and_tracks_status(): void
    {
        Queue::fake();
        Storage::fake('local');

        $teacher = User::factory()->teacher()->create();
        $chapter = Chapter::factory()->forTeacher($teacher)->create();
        Sanctum::actingAs($teacher);

        $response = $this->postJson("/api/chapters/{$chapter->id}/upload?async=1", [
            'file' => UploadedFile::fake()->create('cours.pdf', 128, 'application/pdf'),
        ]);

        $response->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushedOn('low', ConvertChapterFile::class);

        $id = $response->json('data.id');
        self::assertIsString($id);

        $this->getJson($response->json('data.status_url'))
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonMissingPath('data.meta.source_path');
    }

    public function test_job_marks_missing_source_as_failed(): void
    {
        Storage::fake('local');

        $teacher = User::factory()->teacher()->create();
        $chapter = Chapter::factory()->forTeacher($teacher)->create();
        $conversionId = 'chapter-conversion-test';
        $sourcePath = 'chapter-conversions/'.$conversionId.'/source.pdf';

        $store = app(AsyncChapterConversionStore::class);
        $store->pending($conversionId, (int) $chapter->id, (int) $teacher->id, [
            'source_path' => $sourcePath,
            'original_name' => 'cours.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
        ]);

        (new ConvertChapterFile($conversionId))->handle(
            $store,
            app(ChapterFileUploadService::class),
            app(LoggerInterface::class),
        );

        self::assertSame('failed', $store->get($conversionId)['status'] ?? null);
        self::assertSame('source_file_missing', $store->get($conversionId)['message'] ?? null);
    }

    public function test_job_skips_already_completed_conversion(): void
    {
        Storage::fake('local');

        $teacher = User::factory()->teacher()->create();
        $chapter = Chapter::factory()->forTeacher($teacher)->create();
        $conversionId = 'completed-chapter-conversion-test';

        $store = app(AsyncChapterConversionStore::class);
        $store->pending($conversionId, (int) $chapter->id, (int) $teacher->id, [
            'source_path' => 'chapter-conversions/missing/source.pdf',
            'original_name' => 'cours.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
        ]);
        $store->completed($conversionId, (int) $chapter->id);

        (new ConvertChapterFile($conversionId))->handle(
            $store,
            app(ChapterFileUploadService::class),
            app(LoggerInterface::class),
        );

        self::assertSame('completed', $store->get($conversionId)['status'] ?? null);
    }
}
