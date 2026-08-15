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
 * file.size=1GB → Server disk saturated by a single upload.
 * Prevented by the 30 MB cap. La règle `max` de Laravel s'exprime en
 * kilo-octets pour un fichier → 30 MB = 30 * 1024 = 30 720 Ko (#576).
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
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for image tests');
        }

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
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for image tests');
        }

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
        // Validation error message is present in errors.file
        $this->assertNotEmpty($response->json('errors.file'));
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
        // Validation error message is present in errors.file
        $this->assertNotEmpty($response->json('errors.file'));
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
        // Validation error message is present in errors.file
        $this->assertNotEmpty($response->json('errors.file'));
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
     * ✅ DOS PREVENTION: File exactly 30 MB passes (boundary).
     * 30 MB = 30 * 1024 = 30 720 Ko (unité de la règle `max` d'un fichier).
     */
    public function test_file_exactly_30mb_passes(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->create('large.pdf', 30 * 1024, 'application/pdf');

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
        ]);

        $this->assertNotEquals(422, $response->status());
    }

    /**
     * ❌ DOS PREVENTION: File just over the boundary (30 MB + 1 Ko) fails,
     * with the announced « 30 MB » message (critère #576 : « 422 avec le message attendu »).
     */
    public function test_file_just_over_30mb_fails(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->create('huge.pdf', 30 * 1024 + 1, 'application/pdf');

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        // Le message doit annoncer la limite réellement appliquée (30 MB).
        $this->assertStringContainsString('30 MB', (string) $response->json('errors.file.0'));
    }

    /**
     * ✅ ACCEPTED: 29 MB file is under the 30 MB cap (issue #576 criterion).
     */
    public function test_file_29mb_passes(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->create('ok.pdf', 29 * 1024, 'application/pdf');

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
        ]);

        // Un upload valide renvoie 200 ou 201 (jamais 422 pour dépassement).
        $this->assertContains($response->status(), [200, 201]);
    }

    /**
     * ❌ DOS PREVENTION: 31 MB file fails (issue #576 criterion).
     * RED avant #576 : la règle `max:31457280` (Ko) ≈ 30 Go laissait passer.
     */
    public function test_file_31mb_fails(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->create('huge.pdf', 31 * 1024, 'application/pdf');

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.file'));
    }

    /**
     * ❌ DOS PREVENTION: 40 MB file fails.
     * Démontre le cœur de #576 : un envoi de 40 Mo passait avant le correctif.
     */
    public function test_file_40mb_fails(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->create('huge.pdf', 40 * 1024, 'application/pdf');

        $response = $this->postJson('/api/files/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.file'));
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
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for image tests');
        }

        Sanctum::actingAs($this->user);
        $images = ['jpg', 'png', 'gif'];

        foreach ($images as $ext) {
            $file = UploadedFile::fake()->image("image.$ext");

            $response = $this->postJson('/api/files/upload', [
                'file' => $file,
            ]);

            $this->assertContains($response->status(), [200, 201], "Failed for $ext");
        }
    }
}
