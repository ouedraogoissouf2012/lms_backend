<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Enums\InstitutionMode;
use App\Jobs\ProcessImportJob;
use App\Models\Classe;
use App\Models\Import;
use App\Models\Institution;
use App\Models\User;
use App\Services\Import\ImportApplyService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #718 lot 2 — confirmation + job low, rapport relisible.
 */
final class ImportJobTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_confirm_queues_on_low_and_job_creates_student(): void
    {
        Queue::fake();
        $teacher = $this->teacher();
        $classe = Classe::factory()->create([
            'institution_id' => $teacher->institution_id,
            'code' => 'B2',
        ]);
        $csv = "nom;prenom;email;telephone;code_classe\nDoe;Jane;jane.import@test.com;22670000999;B2\n";

        $preview = $this->asTenant($teacher)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('ok.csv', $csv),
            ])
            ->assertOk();

        $importId = $preview->json('data.import_id');
        $before = User::query()->count();

        $this->asTenant($teacher)
            ->postJson('/api/lms/imports/'.$importId.'/confirm')
            ->assertOk();

        Queue::assertPushedOn('low', ProcessImportJob::class);

        $job = new ProcessImportJob((int) $importId, (int) $teacher->institution_id);
        $job->handle(app(TenantManager::class), app(ImportApplyService::class));

        $this->assertSame($before + 1, User::query()->count());
        $student = User::query()->where('email', 'jane.import@test.com')->first();
        $this->assertNotNull($student);
        $this->assertTrue($classe->etudiants()->where('users.id', $student->id)->exists());
        $this->asTenant($teacher)
            ->getJson('/api/lms/imports/'.$importId)
            ->assertOk()
            ->assertJsonPath('data.status', Import::STATUS_DONE);
    }

    public function test_failed_marks_import_without_tenant(): void
    {
        $teacher = $this->teacher();
        $import = Import::query()->create([
            'institution_id' => $teacher->institution_id,
            'user_id' => $teacher->id,
            'path' => 'imports/x.csv',
            'original_name' => 'x.csv',
            'status' => Import::STATUS_RUNNING,
            'ok_count' => 0,
            'error_count' => 0,
        ]);
        app(TenantManager::class)->reset();

        $job = new ProcessImportJob((int) $import->id, (int) $teacher->institution_id);
        $job->failed(new \RuntimeException('queue exhausted'));

        $this->assertSame(
            Import::STATUS_FAILED,
            $import->fresh()?->status,
        );
    }

    private function teacher(): User
    {
        // Importer suppose que l'ecole tient sa propre liste (#805) : le mode
        // est desormais une premisse explicite de ces tests, et non un implicite.
        $school = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);
        app(TenantManager::class)->set($school);

        return User::factory()->teacher()->create(['institution_id' => $school->id]);
    }
}
