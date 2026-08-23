<?php

declare(strict_types=1);

namespace Tests\Feature\Chapter;

use App\Models\Chapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #605 — `chapters.video_provider` doit accepter n'importe quel provider,
 * pas seulement l'ancien ENUM `['youtube','vimeo','custom']`.
 *
 * Le code d'attache de replay visio écrit `'external'` (défaut) et `'s3'`
 * (`SeanceRecordingAttachmentResolver:153`). Sous MySQL, l'ENUM restrictif
 * rejetait ces valeurs (`1265 Data truncated`) — invisible sous SQLite (VARCHAR).
 * Cette garde structurelle échouait sur la jambe MySQL de la CI (#574) avant la
 * migration de normalisation ; elle documente désormais l'invariant.
 *
 * @see database/migrations/2026_08_23_000001_normalize_chapters_video_provider_to_string.php
 */
final class ChapterVideoProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_chapter_accepts_a_non_enum_video_provider(): void
    {
        $chapter = Chapter::factory()->create([
            'content_type' => 'video',
            'video_url' => 'https://cdn.example.test/replay.mp4',
            'video_provider' => 's3',
        ]);

        self::assertSame('s3', $chapter->fresh()->video_provider);
    }

    public function test_chapter_accepts_external_provider_default(): void
    {
        $chapter = Chapter::factory()->create([
            'content_type' => 'video',
            'video_url' => 'https://cdn.example.test/old.mp4',
            'video_provider' => 'external',
        ]);

        self::assertSame('external', $chapter->fresh()->video_provider);
    }
}
