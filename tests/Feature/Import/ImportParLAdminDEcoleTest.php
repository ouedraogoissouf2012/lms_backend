<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Enums\InstitutionMode;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Institution;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1708 — l'admin d'une école importe ses élèves, comme le coordinateur.
 *
 * L'écran d'import (front#655) s'ouvre au rôle `admin`, et la couche métier
 * l'admet déjà : `ImportPreviewRequest::authorize()` et les contrôleurs de
 * confirmation et de lecture passent par `isAdmin()`. Seule la garde de route
 * l'oubliait, si bien qu'un admin recevait 403 sur les trois routes.
 *
 * Le superAdmin passe toutes les gardes de rôle : un test écrit avec lui
 * resterait vert sans la correction. Ces tests emploient donc un compte de
 * rôle `admin` exactement, sous un vrai jeton (le scope d'école est actif).
 */
final class ImportParLAdminDEcoleTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_l_admin_d_une_ecole_autonome_analyse_un_csv(): void
    {
        $admin = $this->adminDe($this->ecole(InstitutionMode::Standalone));

        $this->asTenant($admin)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('ok.csv', "nom;prenom;telephone\nDoe;Jane;70000000\n"),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.counts.ok', 1);

        $this->assertDatabaseHas('imports', ['user_id' => $admin->id, 'status' => Import::STATUS_PREVIEWED]);
    }

    public function test_l_admin_confirme_l_import_d_un_collegue_de_son_ecole(): void
    {
        Queue::fake();
        $ecole = $this->ecole(InstitutionMode::Standalone);
        $import = $this->importAnalysePar(User::factory()->teacher()->create(['institution_id' => $ecole->id]));
        $admin = $this->adminDe($ecole);

        $this->asTenant($admin)
            ->postJson("/api/lms/imports/{$import->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', Import::STATUS_QUEUED);

        Queue::assertPushedOn('low', ProcessImportJob::class);
    }

    public function test_l_admin_relit_le_rapport_d_un_import_de_son_ecole(): void
    {
        $ecole = $this->ecole(InstitutionMode::Standalone);
        $import = $this->importAnalysePar(User::factory()->coordinator()->create(['institution_id' => $ecole->id]));
        $admin = $this->adminDe($ecole);

        $this->asTenant($admin)
            ->getJson("/api/lms/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.import_id', $import->id);
    }

    public function test_l_admin_d_une_autre_ecole_ne_voit_ni_ne_confirme_l_import(): void
    {
        // Ouvrir la route à l'admin ne doit pas l'ouvrir aux autres écoles :
        // sous vrai jeton, l'import d'une autre école est introuvable (404),
        // et non simplement interdit.
        Queue::fake();
        $ecoleA = $this->ecole(InstitutionMode::Standalone);
        $import = $this->importAnalysePar(User::factory()->teacher()->create(['institution_id' => $ecoleA->id]));
        $adminB = $this->adminDe($this->ecole(InstitutionMode::Standalone));

        $this->asTenant($adminB)->getJson("/api/lms/imports/{$import->id}")->assertNotFound();
        $this->asTenant($adminB)->postJson("/api/lms/imports/{$import->id}/confirm")->assertNotFound();

        Queue::assertNothingPushed();
        $this->assertSame(Import::STATUS_PREVIEWED, Import::query()->withoutGlobalScope('institution')->findOrFail($import->id)->status);
    }

    public function test_l_admin_d_une_ecole_klassci_n_importe_pas(): void
    {
        // Le second volet : une école branchée à KLASSCI reçoit ses élèves de
        // la synchronisation. Admettre le rôle ne lève pas cette règle-là.
        $admin = $this->adminDe($this->ecole(InstitutionMode::Klassci));

        $this->asTenant($admin)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('x.csv', "nom;prenom\nDoe;Jane\n"),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertDatabaseCount('imports', 0);
    }

    private function ecole(InstitutionMode $mode): Institution
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);
        app(TenantManager::class)->set($ecole);

        return $ecole;
    }

    private function adminDe(Institution $ecole): User
    {
        $admin = User::factory()->admin()->create(['institution_id' => $ecole->id]);
        $this->assertSame('admin', $admin->role, 'la preuve exige le rôle admin exact, pas superAdmin');

        return $admin;
    }

    private function importAnalysePar(User $auteur): Import
    {
        return Import::query()->create([
            'institution_id' => $auteur->institution_id,
            'user_id' => $auteur->id,
            'path' => 'imports/x.csv',
            'original_name' => 'x.csv',
            'status' => Import::STATUS_PREVIEWED,
            'ok_count' => 1,
            'error_count' => 0,
        ]);
    }
}
