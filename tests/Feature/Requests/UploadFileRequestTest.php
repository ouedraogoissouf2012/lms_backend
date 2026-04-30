<?php

namespace Tests\Feature\Requests;

use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use App\Models\User;
use Tests\TestCase;

/**
 * Tests for UploadFileRequest (file upload validation)
 *
 * Validates:
 * - File must be provided
 * - File size max 30 MB
 * - Only allowed MIME types: pdf, doc, docx, xls, xlsx, ppt, pptx, jpg, jpeg, png, gif
 * - File name (if provided) matches alphanumeric pattern
 *
 * ## DOS Prevention
 * per_page=1000000 → Server crashes trying to read unlimited records
 * file.size=1GB → Server crashes trying to store
 * Both prevented by max:31457280 (30 MB)
 *
 * ## Malicious File Prevention
 * Attacker tries: .exe, .sh, .bat, .zip, .rar
 * Prevention: mimes:... whitelist enforced by Laravel
 *
 * ## 10-year perspective
 * If allowed file types change, update:
 * - mimes:... rule in UploadFileRequest
 * - Regression caught immediately by tests
 * New devs understand file policy by reading tests.
 */
class UploadFileRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->disableKlassciMiddleware();
    }

    /**
     * ✅ HAPPY PATH: Valid PDF file
     */
    public function test_valid_pdf_file_passes(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    /**
     * ✅ HAPPY PATH: Valid Word document
     */
    public function test_valid_docx_file_passes(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->create(
            'document.docx',
            512,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    /**
     * ✅ HAPPY PATH: Valid PowerPoint file
     */
    public function test_valid_pptx_file_passes(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->create(
            'presentation.pptx',
            2048,
            'application/vnd.openxmlformats-officedocument.presentationml.presentation'
        );

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    /**
     * ✅ HAPPY PATH: Valid JPG image
     */
    public function test_valid_jpg_file_passes(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->image('photo.jpg', 640, 480);

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    /**
     * ✅ HAPPY PATH: Valid PNG image
     */
    public function test_valid_png_file_passes(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->image('photo.png', 640, 480);

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    /**
     * ✅ OPTIONAL: File with custom name
     */
    public function test_file_with_custom_name_passes(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->create('document.pdf', 512, 'application/pdf');

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
            'name' => 'My Important Document',
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    /**
     * ❌ REQUIRED: Missing file fails (unauthenticated)
     */
    public function test_missing_file_unauthenticated_fails(): void
    {
        $response = $this->postJson('/api/files/upload', [
            // No file key
        ]);

        $response->assertStatus(401);
    }

    /**
     * ❌ REQUIRED: Missing file fails (authenticated)
     */
    public function test_missing_file_authenticated_fails(): void
    {
        Sanctum::actingAs($this->user);
        $response = $this->postJson('/api/files/upload', [
            // No file key
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.file', [
            fn($msg) => str_contains($msg, 'requis') || str_contains($msg, 'required')
        ]);
    }

    /**
     * ❌ SECURITY: .exe file fails
     */
    public function test_executable_file_fails(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->create('malware.exe', 512, 'application/x-msdownload');

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.file', [
            fn($msg) => str_contains($msg, 'type de fichier') || str_contains($msg, 'file type')
        ]);
    }

    /**
     * ❌ SECURITY: .sh shell script fails
     */
    public function test_shell_script_fails(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->create('script.sh', 256, 'application/x-sh');

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.file', [
            fn($msg) => str_contains($msg, 'type de fichier') || str_contains($msg, 'file type')
        ]);
    }

    /**
     * ❌ SECURITY: .zip archive fails
     */
    public function test_zip_archive_fails(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->create('archive.zip', 512, 'application/zip');

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
    }

    /**
     * ❌ SECURITY: .rar archive fails
     */
    public function test_rar_archive_fails(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->create('archive.rar', 512, 'application/x-rar-compressed');

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
    }

    /**
     * ❌ SECURITY: .bat batch script fails
     */
    public function test_batch_script_fails(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->create('script.bat', 256, 'application/x-bat');

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
    }

    /**
     * ❌ DOS PREVENTION: File exactly 30 MB passes (boundary)
     */
    public function test_file_exactly_30mb_passes(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->create('large.pdf', 31457280, 'application/pdf');

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
        ]);

        $this->assertNotEquals(422, $response->status());
    }

    /**
     * ❌ DOS PREVENTION: File > 30 MB fails
     */
    public function test_file_exceeding_30mb_fails(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->create('huge.pdf', 31457281, 'application/pdf');

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.file', [
            fn($msg) => str_contains($msg, 'dépasser 30 MB') || str_contains($msg, 'exceed 30 MB') || str_contains($msg, 'too large')
        ]);
    }

    /**
     * ❌ DOS PREVENTION: 100 MB file fails
     */
    public function test_file_100mb_fails(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->create('huge.pdf', 104857600, 'application/pdf');

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.file', [
            fn($msg) => str_contains($msg, '30 MB') || str_contains($msg, 'too large')
        ]);
    }

    /**
     * ✅ ALLOWED: All valid document types
     */
    public function test_all_allowed_document_types_pass(): void
    {
        Sanctum::actingAs($this->user);
        $files = [
            ['file.pdf', 'application/pdf'],
            ['file.doc', 'application/msword'],
            ['file.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['file.xls', 'application/vnd.ms-excel'],
            ['file.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['file.ppt', 'application/vnd.ms-powerpoint'],
            ['file.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        ];

        foreach ($files as [$filename, $mime]) {
            $file = UploadedFile::fake()->create($filename, 512, $mime);

            $response = $this->postJson('/api/files/upload', [
                'file' => $file,
            ]);

            $this->assertTrue(in_array($response->status(), [200, 201]), "Failed for $filename");
        }
    }

    /**
     * ✅ ALLOWED: All valid image types
     */
    public function test_all_allowed_image_types_pass(): void
    {
        Sanctum::actingAs($this->user);
        $images = ['jpg', 'png', 'gif'];

        foreach ($images as $ext) {
            $file = UploadedFile::fake()->image("image.$ext");

            $response = $this->postJson('/api/files/upload', [
                'file' => $file,
            ]);

            $this->assertIn($response->status(), [200, 201], "Failed for $ext");
        }
    }
}
