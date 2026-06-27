<?php

declare(strict_types=1);

namespace Tests\Feature\Files;

use App\Models\File;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de `FileController`
 * (axe #1, groupe G1 — test-first AVANT migration vers `RespondsWithJson`).
 *
 * Verrouille la forme JSON exacte des enveloppes JSON migrables :
 *   - succès : présence/absence des clés `message`/`data` ;
 *   - erreurs déterministes (404/403) : `assertExactJson`.
 *
 * Les chemins défensifs `upload` 422 (fichier absent — déjà capté en amont par
 * `UploadFileRequest::required`) et 500 (échec de stockage) ne sont pas
 * déclenchables sans mock du service : ils sont migrés 1:1 sans test dédié.
 *
 * @see app/Http/Controllers/API/FileController.php
 */
final class FileResponseTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->owner = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
    }

    private function ownedFile(bool $isPublic = false): File
    {
        return File::factory()->create([
            'user_id' => $this->owner->id,
            'institution_id' => $this->institution->id,
            'is_public' => $isPublic,
        ]);
    }

    // ───────────────────────── index ─────────────────────────

    public function test_index_returns_success_with_data_without_message(): void
    {
        $this->ownedFile();
        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/files');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayNotHasKey('message', $body);
    }

    // ───────────────────────── upload ─────────────────────────

    public function test_upload_returns_201_with_message_and_data(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/files/upload', [
            'file' => UploadedFile::fake()->create('document.pdf', 120, 'application/pdf'),
        ]);

        $response->assertStatus(201);
        $body = $response->json();
        $this->assertSame(['success', 'message', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame('Fichier uploadé avec succès', $body['message']);
        $this->assertArrayHasKey('id', $body['data']);
    }

    // ───────────────────────── show ─────────────────────────

    public function test_show_returns_success_with_data_without_message(): void
    {
        $file = $this->ownedFile();
        Sanctum::actingAs($this->owner);

        $response = $this->getJson("/api/files/{$file->id}");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayNotHasKey('message', $body);
    }

    public function test_show_missing_file_returns_404_error_envelope(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/files/999999');

        $response->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Fichier non trouvé']);
    }

    public function test_show_forbidden_file_returns_403_error_envelope(): void
    {
        $file = $this->ownedFile(isPublic: false);
        $otherStudent = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
        Sanctum::actingAs($otherStudent);

        $response = $this->getJson("/api/files/{$file->id}");

        $response->assertStatus(403)
            ->assertExactJson(['success' => false, 'message' => 'Accès refusé']);
    }

    // ───────────────────────── download ─────────────────────────

    public function test_download_missing_file_returns_404_error_envelope(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/files/999999/download');

        $response->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Fichier non trouvé']);
    }

    public function test_download_forbidden_file_returns_403_error_envelope(): void
    {
        $file = $this->ownedFile(isPublic: false);
        $otherStudent = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
        Sanctum::actingAs($otherStudent);

        $response = $this->getJson("/api/files/{$file->id}/download");

        $response->assertStatus(403)
            ->assertExactJson(['success' => false, 'message' => 'Accès refusé']);
    }

    public function test_download_missing_physical_file_returns_404_error_envelope(): void
    {
        Storage::fake('local');
        $file = $this->ownedFile();
        Sanctum::actingAs($this->owner);

        $response = $this->getJson("/api/files/{$file->id}/download");

        $response->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Fichier physique introuvable']);
    }

    // ───────────────────────── update ─────────────────────────

    public function test_update_returns_200_with_message_and_data(): void
    {
        $file = $this->ownedFile();
        Sanctum::actingAs($this->owner);

        $response = $this->putJson("/api/files/{$file->id}", ['description' => 'Nouvelle description']);

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'message', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame('Fichier mis à jour', $body['message']);
        $this->assertArrayHasKey('id', $body['data']);
    }

    // ───────────────────────── destroy ─────────────────────────

    public function test_destroy_returns_success_message_envelope(): void
    {
        $file = $this->ownedFile();
        Sanctum::actingAs($this->owner);

        $response = $this->deleteJson("/api/files/{$file->id}");

        $response->assertStatus(200)
            ->assertExactJson(['success' => true, 'message' => 'Fichier supprimé']);
    }

    // ───────────────────────── stats ─────────────────────────

    public function test_stats_returns_success_with_data_without_message(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/files/stats');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayNotHasKey('message', $body);
    }
}
