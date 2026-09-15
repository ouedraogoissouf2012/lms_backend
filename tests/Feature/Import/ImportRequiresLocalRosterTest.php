<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Enums\InstitutionMode;
use App\Models\Import;
use App\Models\Institution;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #718 / #805 — importer suppose d'être maître de sa liste d'apprenants.
 *
 * Une école branchée à KLASSCI reçoit ses élèves de la synchronisation. Un
 * import local y créerait une seconde liste à côté de celle qui fait foi, que
 * rien ne réconcilierait ensuite.
 *
 * Le rôle ne suffit donc pas : jusqu'ici les deux routes ne filtraient QUE
 * lui. Le refus vit sur le serveur et nulle part ailleurs — cacher la carte
 * dans l'interface ne ferme aucune route, et c'est par l'URL qu'on y arrive.
 */
final class ImportRequiresLocalRosterTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_une_ecole_klassci_ne_peut_pas_analyser_un_csv(): void
    {
        $teacher = $this->enseignantDe(InstitutionMode::Klassci);

        $this->asTenant($teacher)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('x.csv', "nom;prenom\nDoe;Jane\n"),
            ], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    public function test_le_refus_precede_toute_lecture_du_fichier(): void
    {
        // Un fichier que la validation rejetterait (trop volumineux) doit
        // quand même rendre 403, et non 422 : la question du droit se tranche
        // AVANT d'ouvrir quoi que ce soit.
        $teacher = $this->enseignantDe(InstitutionMode::Klassci);

        $this->asTenant($teacher)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->create('enorme.csv', 6 * 1024),
            ], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    public function test_une_ecole_autonome_analyse_normalement(): void
    {
        $teacher = $this->enseignantDe(InstitutionMode::Standalone);

        $this->asTenant($teacher)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('ok.csv', "nom;prenom;telephone\nDoe;Jane;70000000\n"),
            ])
            ->assertOk()
            ->assertJsonPath('data.counts.ok', 1);
    }

    public function test_une_ecole_klassci_ne_peut_pas_confirmer_un_import(): void
    {
        // Même si un import avait été créé avant la bascule du mode, sa
        // confirmation — la seule étape qui écrit vraiment — reste fermée.
        $teacher = $this->enseignantDe(InstitutionMode::Klassci);
        $import = Import::query()->create([
            'institution_id' => $teacher->institution_id,
            'user_id' => $teacher->id,
            'path' => 'imports/x.csv',
            'original_name' => 'x.csv',
            'status' => Import::STATUS_PREVIEWED,
            'ok_count' => 1,
            'error_count' => 0,
        ]);

        $this->asTenant($teacher)
            ->post("/api/lms/imports/{$import->id}/confirm", [], ['Accept' => 'application/json'])
            ->assertStatus(403);

        $this->assertSame(Import::STATUS_PREVIEWED, $import->fresh()->status);
    }

    private function enseignantDe(InstitutionMode $mode): User
    {
        $school = Institution::factory()->create(['mode' => $mode]);
        app(TenantManager::class)->set($school);

        return User::factory()->teacher()->create(['institution_id' => $school->id]);
    }
}
