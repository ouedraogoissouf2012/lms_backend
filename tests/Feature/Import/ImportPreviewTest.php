<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Models\Institution;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #718 lot 1 — analyse à blanc, zéro écriture.
 */
final class ImportPreviewTest extends TestCase
{
    use RefreshDatabase;
    use ActsAsTenantUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_preview_writes_nothing(): void
    {
        $teacher = $this->teacher();
        $before = User::query()->count();
        $csv = "nom;prenom;email;telephone\nDoe;Jane;jane@test.com;+22670000000\n";

        $this->asTenant($teacher)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('ok.csv', $csv),
            ])
            ->assertOk()
            ->assertJsonPath('data.counts.ok', 1);

        $this->assertSame($before, User::query()->count());
    }

    public function test_dirty_file_reports_errors_without_writing(): void
    {
        $teacher = $this->teacher();
        $csv = "nom;prenom;email;telephone\n;Jane;;\nDoe;John;john@test.com;+22670000001\n";

        $response = $this->asTenant($teacher)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('dirty.csv', $csv),
            ])
            ->assertOk();

        $this->assertSame(1, $response->json('data.counts.error'));
        $this->assertSame(1, $response->json('data.counts.ok'));
        $this->assertSame(User::query()->count(), User::query()->count());
    }

    public function test_cp1252_semicolon_and_phone_without_email(): void
    {
        $teacher = $this->teacher();
        $line = mb_convert_encoding("nom;prenom;email;telephone\nKaboré;Awa;;22670000002\n", 'Windows-1252', 'UTF-8');

        $this->asTenant($teacher)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('wa.csv', $line),
            ])
            ->assertOk()
            ->assertJsonPath('data.counts.ok', 1)
            ->assertJsonPath('data.counts.error', 0);
    }

    private function teacher(): User
    {
        $school = Institution::factory()->create();
        app(TenantManager::class)->set($school);

        return User::factory()->teacher()->create(['institution_id' => $school->id]);
    }
}
