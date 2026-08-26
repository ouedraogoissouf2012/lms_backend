<?php

declare(strict_types=1);

namespace Tests\Feature\Chapter;

use App\Models\Chapter;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * #620 — les PNG de diapositives ne sont plus énumérables via /storage/.
 */
final class ChapterSlideAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BYTES = 'fake-png-bytes-620';

    public function test_unsigned_slide_url_is_rejected(): void
    {
        $chapter = $this->chapterWithSlide($this->institution());

        $this->get("/api/chapters/{$chapter->id}/slides/1")->assertStatus(403);
    }

    public function test_signed_url_returns_the_png_bytes(): void
    {
        $institution = $this->institution();
        $teacher = $this->user($institution);
        $chapter = $this->chapterWithSlide($institution, $teacher);

        $url = $this->actingWithToken($teacher)
            ->getJson("/api/chapters/{$chapter->id}")
            ->assertOk()
            ->json('data.slides_images.0');

        self::assertIsString($url);
        self::assertStringContainsString('/slides/1', $url);

        $response = $this->get($url);
        $response->assertOk();
        self::assertSame(self::PNG_BYTES, $response->streamedContent());
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $chapter = $this->chapterWithSlide($this->institution());
        $url = URL::temporarySignedRoute(
            'chapters.slides.show',
            now()->addHour(),
            ['chapter' => $chapter->id, 'slide' => 1],
        );

        $this->get($url.'x')->assertStatus(403);
    }

    public function test_outsider_never_receives_a_signature(): void
    {
        $chapter = $this->chapterWithSlide($this->institution());
        $outsider = $this->user($this->institution());

        $this->actingWithToken($outsider)
            ->getJson("/api/chapters/{$chapter->id}")
            ->assertStatus(404);
    }

    public function test_htaccess_denies_public_slides(): void
    {
        $content = (string) file_get_contents(base_path('storage/app/public/chapters/.htaccess'));

        self::assertStringContainsString('slides/', $content);
        self::assertStringContainsString('[F,L]', $content);
    }

    private function actingWithToken(User $user): static
    {
        return $this->withToken($user->createToken('test-620')->plainTextToken);
    }

    private function institution(): Institution
    {
        return Institution::factory()->create(['is_active' => true]);
    }

    private function user(Institution $institution): User
    {
        return User::factory()->for($institution)->create([
            'role' => 'enseignant',
            'last_klassci_sync' => now(),
        ]);
    }

    private function chapterWithSlide(Institution $institution, ?User $teacher = null): Chapter
    {
        Storage::fake('public');
        $teacher ??= $this->user($institution);
        $lesson = Lesson::factory()->create(['institution_id' => $institution->id]);
        $chapter = Chapter::factory()->create([
            'lesson_id' => $lesson->id,
            'institution_id' => $institution->id,
            'enseignant_id' => $teacher->id,
            'slides_images' => null,
        ]);

        $path = "chapters/{$chapter->id}/slides/slide_001.png";
        Storage::disk('public')->put($path, self::PNG_BYTES);
        $chapter->update(['slides_images' => [$path]]);

        return $chapter->refresh();
    }
}
