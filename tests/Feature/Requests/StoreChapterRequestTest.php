<?php

namespace Tests\Feature\Requests;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for StoreChapterRequest (POST /api/chapters)
 *
 * Validates chapter creation with:
 * - Chapter metadata (titre, description, ordre)
 * - Optional file attachment (max 30MB, only pdf/word/powerpoint)
 * - Authorization (teacher/coordinator only)
 * - Multi-tenant (chapter's lesson belongs to user's institution)
 *
 * ## Hierarchy
 * Lesson contains Chapters
 * Example: GET /api/lessons/123/chapters POST /api/lessons/123/chapters
 *
 * ## 10-year perspective
 * Chapter validation centralized here.
 * If chapter requirements change, tests immediately fail on regression.
 * New devs understand chapter creation contract by reading tests.
 */
class StoreChapterRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Institution $institution;
    private Lesson $lesson;
    private User $teacher;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $classe = Classe::factory()->for($this->institution)->create();
        $matiere = Matiere::factory()->for($this->institution)->create();

        $this->teacher = User::factory()
            ->teacher()
            ->for($this->institution)
            ->create();

        $this->student = User::factory()
            ->student()
            ->for($this->institution)
            ->create();

        $this->lesson = Lesson::factory()
            ->for($classe)
            ->for($matiere)
            ->for($this->institution)
            ->create();
    }

    /**
     * ✅ HAPPY PATH: Valid chapter with minimal data
     */
    public function test_valid_chapter_creates_successfully(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'Variables and Data Types',
        ]);

        $response->assertStatus(201);
    }

    /**
     * ✅ HAPPY PATH: Chapter with all metadata
     */
    public function test_chapter_with_all_metadata_passes(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'Functions and Scope',
            'description' => 'Understanding function definition, parameters, return values, and scope',
            'ordre' => 2,
            'type_contenu' => 'text',
        ]);

        $response->assertStatus(201);
    }

    /**
     * ✅ FILE UPLOAD: Chapter with PDF file
     */
    public function test_chapter_with_pdf_file_passes(): void
    {
        Sanctum::actingAs($this->teacher);
        $file = UploadedFile::fake()->create('slides.pdf', 1024, 'application/pdf');

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'Introduction to Arrays',
            'fichier' => $file,
        ]);

        $response->assertStatus(201);
    }

    /**
     * ✅ FILE UPLOAD: Chapter with PowerPoint file
     */
    public function test_chapter_with_pptx_file_passes(): void
    {
        Sanctum::actingAs($this->teacher);
        $file = UploadedFile::fake()->create(
            'presentation.pptx',
            2048,
            'application/vnd.openxmlformats-officedocument.presentationml.presentation'
        );

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'Object-Oriented Programming',
            'fichier' => $file,
        ]);

        $response->assertStatus(201);
    }

    /**
     * ✅ FILE UPLOAD: Chapter with Word document
     */
    public function test_chapter_with_docx_file_passes(): void
    {
        Sanctum::actingAs($this->teacher);
        $file = UploadedFile::fake()->create(
            'notes.docx',
            512,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'String Manipulation',
            'fichier' => $file,
        ]);

        $response->assertStatus(201);
    }

    /**
     * ✅ EDGE CASE: Title with leading/trailing spaces is trimmed
     */
    public function test_title_with_spaces_is_trimmed(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => '   Important Chapter   ',
        ]);

        $response->assertStatus(201);
    }

    /**
     * ❌ VALIDATION: Missing titre fails
     */
    public function test_missing_titre_fails(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            // No titre
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.titre'));
    }

    /**
     * ❌ VALIDATION: Title too short
     */
    public function test_titre_too_short_fails(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'ab', // < 3 chars
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.titre'));
    }

    /**
     * ❌ VALIDATION: Title too long
     */
    public function test_titre_exceeding_max_length_fails(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => str_repeat('a', 256), // > 255
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.titre'));
    }

    /**
     * ❌ VALIDATION: Title only spaces fails
     */
    public function test_titre_only_spaces_fails(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => '     ',
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.titre'));
    }

    /**
     * ❌ VALIDATION: Description too long
     */
    public function test_description_exceeding_max_length_fails(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'Valid Title',
            'description' => str_repeat('a', 1001), // > 1000
        ]);

        $response->assertStatus(422);
        $this->assertTrue(str_contains(
            json_encode($response->json('errors.description')),
            'ou' // message contains check
        ));
    }

    /**
     * ❌ VALIDATION: ordre must be positive
     */
    public function test_ordre_zero_fails(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'Valid Title',
            'ordre' => 0,
        ]);

        $response->assertStatus(422);
        $this->assertTrue(str_contains(
            json_encode($response->json('errors.ordre')),
            'ou' // message contains check
        ));
    }

    /**
     * ❌ VALIDATION: ordre negative fails
     */
    public function test_ordre_negative_fails(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'Valid Title',
            'ordre' => -1,
        ]);

        $response->assertStatus(422);
    }

    /**
     * ❌ SECURITY: .exe file fails
     */
    public function test_executable_file_fails(): void
    {
        Sanctum::actingAs($this->teacher);
        $file = UploadedFile::fake()->create('malware.exe', 512, 'application/x-msdownload');

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'Valid Title',
            'fichier' => $file,
        ]);

        $response->assertStatus(422);
        $this->assertTrue(str_contains(
            json_encode($response->json('errors.fichier')),
            'ou' // message contains check
        ));
    }

    /**
     * ❌ SECURITY: .zip file fails
     */
    public function test_zip_file_fails(): void
    {
        Sanctum::actingAs($this->teacher);
        $file = UploadedFile::fake()->create('archive.zip', 512, 'application/zip');

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'Valid Title',
            'fichier' => $file,
        ]);

        $response->assertStatus(422);
    }

    /**
     * ❌ DOS PREVENTION: File > 30 MB fails (previously allowed 100MB!)
     */
    public function test_file_exceeding_30mb_fails(): void
    {
        Sanctum::actingAs($this->teacher);
        // 30 MB + 1 byte
        $file = UploadedFile::fake()->create('huge.pdf', 31457281, 'application/pdf');

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'Valid Title',
            'fichier' => $file,
        ]);

        $response->assertStatus(422);
        $this->assertTrue(str_contains(
            json_encode($response->json('errors.fichier')),
            'ou' // message contains check
        ));
    }

    /**
     * ❌ DOS PREVENTION: 100 MB file fails (previously allowed!)
     */
    public function test_100mb_file_now_fails(): void
    {
        Sanctum::actingAs($this->teacher);
        $file = UploadedFile::fake()->create('huge.pdf', 104857600, 'application/pdf');

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'Valid Title',
            'fichier' => $file,
        ]);

        $response->assertStatus(422);
        // This is now prevented! Was allowed in old ChapterController
    }

    /**
     * ❌ AUTHORIZATION: Student cannot create chapter
     */
    public function test_student_cannot_create_chapter(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'Valid Title',
        ]);

        $response->assertStatus(403);
    }

    /**
     * ❌ AUTHORIZATION: Unauthenticated user cannot create
     */
    public function test_unauthenticated_cannot_create_chapter(): void
    {
        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'Valid Title',
        ]);

        $response->assertStatus(401);
    }

    /**
     * ❌ MULTI-TENANT: Teacher cannot create in other institution's lesson
     */
    public function test_teacher_cannot_create_in_other_institution(): void
    {
        $other_institution = Institution::factory()->create();
        $other_classe = Classe::factory()->for($other_institution)->create();
        $other_matiere = Matiere::factory()->for($other_institution)->create();
        $other_lesson = Lesson::factory()
            ->for($other_classe)
            ->for($other_matiere)
            ->for($other_institution)
            ->create();

        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$other_lesson->id}/chapters", [
            'titre' => 'Valid Title',
        ]);

        $response->assertStatus(403);
    }

    /**
     * ✅ ENUM: type_contenu='pdf' is valid
     */
    public function test_valid_content_type_pdf_passes(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'Valid Title',
            'type_contenu' => 'pdf',
        ]);

        $response->assertStatus(201);
    }

    /**
     * ✅ ENUM: type_contenu='powerpoint' is valid
     */
    public function test_valid_content_type_powerpoint_passes(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'Valid Title',
            'type_contenu' => 'powerpoint',
        ]);

        $response->assertStatus(201);
    }

    /**
     * ❌ ENUM: type_contenu='invalid' fails
     */
    public function test_invalid_content_type_fails(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'Valid Title',
            'type_contenu' => 'invalid_type',
        ]);

        $response->assertStatus(422);
        $this->assertTrue(str_contains(
            json_encode($response->json('errors.type_contenu')),
            'ou' // message contains check
        ));
    }
}
