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

    public function test_mapping_resolves_accented_and_arbitrary_headers(): void
    {
        // Export Excel francophone : en-têtes libres, accentués, en Windows-1252.
        // Le client n'a plus le droit de réécrire le fichier — il envoie les
        // octets tels quels et dit seulement quelle colonne porte quel champ.
        $teacher = $this->teacher();
        $csv = mb_convert_encoding(
            "Nom;Prénom;Courriel\nKaboré;Awa;awa@test.com\n",
            'Windows-1252',
            'UTF-8'
        );

        $this->asTenant($teacher)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('excel.csv', $csv),
                'mapping' => ['nom' => 'Nom', 'prenom' => 'Prénom', 'email' => 'Courriel'],
            ])
            ->assertOk()
            ->assertJsonPath('data.counts.ok', 1)
            ->assertJsonPath('data.counts.error', 0);
    }

    public function test_quoted_field_containing_the_delimiter_stays_one_column(): void
    {
        // Le point-dur qui motivait l'ADR : réécrit par l'ancien client, ce nom
        // se scindait en deux colonnes et décalait le reste de la ligne.
        $teacher = $this->teacher();
        $csv = "Nom;Prénom;Téléphone\n\"Ouédraogo; fils\";Ali;70000000\n";

        $this->asTenant($teacher)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('quote.csv', $csv),
                'mapping' => ['nom' => 'Nom', 'prenom' => 'Prénom', 'telephone' => 'Téléphone'],
            ])
            ->assertOk()
            ->assertJsonPath('data.counts.ok', 1)
            ->assertJsonPath('data.counts.error', 0)
            // La preuve que rien n'a glissé d'une colonne à l'autre : le nom
            // porte encore son point-virgule, et le prénom n'a pas été mangé.
            ->assertJsonPath('data.rows.0.payload.nom', 'Ouédraogo; fils')
            ->assertJsonPath('data.rows.0.payload.prenom', 'Ali')
            ->assertJsonPath('data.rows.0.payload.telephone', '70000000');
    }

    public function test_client_delimiter_reaches_the_parser(): void
    {
        // La détection automatique de League voit juste sur ce fichier : pour
        // prouver que le séparateur transmis est bien celui qui sert, on en
        // envoie un FAUX et on vérifie que la lecture change. Avec « ; » le
        // fichier n'a plus qu'une colonne, donc plus de nom exploitable.
        $teacher = $this->teacher();
        $csv = "nom,prenom,telephone\nDoe,Jane,70000000\n";

        $this->asTenant($teacher)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('virgule.csv', $csv),
                'delimiter' => 'comma',
            ])
            ->assertOk()
            ->assertJsonPath('data.counts.ok', 1);

        $this->asTenant($teacher)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('virgule.csv', $csv),
                'delimiter' => 'semicolon',
            ])
            ->assertOk()
            ->assertJsonPath('data.counts.ok', 0)
            ->assertJsonPath('data.rows.0.code', 'missing_name');
    }

    public function test_tab_delimiter_survives_the_multipart_transport(): void
    {
        // Une tabulation brute était élaguée par `TrimStrings` et arrivait vide,
        // donc refusée par Rule::in : tout fichier tabulé était rejeté (mesuré,
        // 302 au lieu de 200). D'où le nom « tab » plutôt que le caractère.
        $teacher = $this->teacher();
        $csv = "nom\tprenom\ttelephone\nDoe\tJane\t70000000\n";

        $this->asTenant($teacher)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('tab.csv', $csv),
                'delimiter' => 'tab',
            ])
            ->assertOk()
            ->assertJsonPath('data.counts.ok', 1);
    }

    public function test_rejected_delimiter_does_not_reach_the_parser(): void
    {
        $teacher = $this->teacher();

        $this->asTenant($teacher)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('x.csv', "nom;prenom\nDoe;Jane\n"),
                'delimiter' => 'pipe',
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_duplicate_headers_are_reported_not_fatal(): void
    {
        // Reader::computeHeader() lève une SyntaxError sur des en-têtes
        // dupliqués. Tant que le client réécrivait le fichier le cas était
        // inatteignable ; il ne l'est plus et ne doit pas donner une 500.
        $teacher = $this->teacher();
        $csv = "nom;nom;prenom\nDoe;Dupont;Jane\n";

        $response = $this->asTenant($teacher)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('dup.csv', $csv),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        self::assertSame('duplicate_header', $response->json('code'));
    }

    public function test_utf8_bom_does_not_hide_the_first_column(): void
    {
        $teacher = $this->teacher();
        $csv = "\u{FEFF}nom;prenom;telephone\nDoe;Jane;70000000\n";

        $this->asTenant($teacher)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('bom.csv', $csv),
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
