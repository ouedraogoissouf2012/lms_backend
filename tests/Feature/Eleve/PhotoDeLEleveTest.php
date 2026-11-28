<?php

declare(strict_types=1);

namespace Tests\Feature\Eleve;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1657, PR 2 : la photo de l'élève d'une école autonome. Sous vrai jeton.
 *
 * - `POST /api/eleves/{eleve}/photo` (gestion) : une image JPEG, PNG ou WebP, 5 Mo au plus. Le serveur la DÉCODE puis
 *   la RÉ-ENCODE (JPEG, 512 px au plus de côté) : le fichier d'origine n'est jamais gardé, ni ses métadonnées (EXIF,
 *   position). Un fichier qui ne se décode pas comme image est refusé (422). Il faut un dossier d'abord.
 * - Stockage PRIVÉ (disque `local`), jamais public. `GET /api/eleves/{eleve}/photo` la sert selon les règles de lecture
 *   du dossier : gestion, élève lui-même, enseignant d'une de ses classes ; sinon 403 ; hors de l'école 404.
 * - `DELETE` la retire, et la purge du compte emporte le fichier.
 * - École KLASSCI : 403 `catalogue_managed_by_klassci`.
 */
final class PhotoDeLEleveTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Storage::fake('local');
    }

    public function test_la_photo_est_re_encodee_reduite_stockee_en_prive_et_servie(): void
    {
        [$admin, $awa] = $this->eleveAvecDossier();

        $depot = $this->deposer($admin, $awa, $this->image('png', 1200, 800));

        $depot->assertCreated()->assertJsonPath('data.photo_url', "/api/eleves/{$awa->id}/photo");
        $this->assertMatchesOpenApiResponse($depot, '/eleves/{eleve}/photo', 'post');
        $fichiers = Storage::disk('local')->allFiles();
        self::assertCount(1, $fichiers);
        self::assertStringEndsWith('.jpg', $fichiers[0]);
        self::assertFalse(Storage::disk('public')->exists($fichiers[0]));

        $servie = $this->asTenant($admin)->get("/api/eleves/{$awa->id}/photo");
        $servie->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $taille = getimagesizefromstring((string) $servie->streamedContent());
        self::assertIsArray($taille);
        self::assertSame([512, 341], [$taille[0], $taille[1]]);

        $this->asTenant($admin)->getJson("/api/eleves/{$awa->id}/dossier")->assertJsonPath('data.dossier.photo_url', "/api/eleves/{$awa->id}/photo");
    }

    public function test_les_metadonnees_d_origine_ne_survivent_pas(): void
    {
        [$admin, $awa] = $this->eleveAvecDossier();
        // Un commentaire JPEG (segment COM) glissé après l'image : il porte une « position ».
        $origine = $this->octets('jpeg', 300, 300)."\xFF\xFE".'GPS-SECRET-48.85N';

        $this->deposer($admin, $awa, UploadedFile::fake()->createWithContent('photo.jpg', $origine))->assertCreated();

        $stockee = Storage::disk('local')->get(Storage::disk('local')->allFiles()[0]);
        self::assertStringNotContainsString('GPS-SECRET', (string) $stockee);
        self::assertNotSame($origine, $stockee);
    }

    public function test_remplacer_la_photo_retire_l_ancienne(): void
    {
        [$admin, $awa] = $this->eleveAvecDossier();
        $this->deposer($admin, $awa, $this->image('png', 200, 200))->assertCreated();

        $this->deposer($admin, $awa, $this->image('jpeg', 200, 200))->assertOk();

        self::assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_422_un_fichier_qui_n_est_pas_une_image_ou_trop_lourd(): void
    {
        [$admin, $awa] = $this->eleveAvecDossier();

        $faux = $this->deposer($admin, $awa, UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo "pas une image";'));
        $faux->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->assertMatchesOpenApiResponse($faux, '/eleves/{eleve}/photo', 'post');
        $this->deposer($admin, $awa, UploadedFile::fake()->image('photo.jpg')->size(6000))->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->deposer($admin, $awa, UploadedFile::fake()->create('photo.gif', 10, 'image/gif'))->assertUnprocessable()->assertJsonValidationErrors('photo');

        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_sans_dossier_pas_de_photo(): void
    {
        $ecole = $this->ecole();
        $admin = $this->membre($ecole, 'admin');

        $this->deposer($admin, $this->membre($ecole, 'etudiant'), $this->image('png', 100, 100))
            ->assertStatus(409)->assertJsonPath('reason', 'dossier_absent');
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_la_lecture_suit_les_regles_du_dossier(): void
    {
        [$admin, $awa, $ecole] = $this->eleveAvecDossier();
        $this->deposer($admin, $awa, $this->image('png', 100, 100))->assertCreated();
        $classe = $this->classe($ecole, '6e A');
        $this->inscrire($awa, $classe, 'actif');
        $sien = $this->membre($ecole, 'enseignant');
        $this->enseigneDans($sien, $classe);

        foreach ([$admin, $awa, $sien] as $lecteur) {
            $this->asTenant($lecteur)->get("/api/eleves/{$awa->id}/photo")->assertOk();
        }
        $this->asTenant($this->membre($ecole, 'enseignant'))->get("/api/eleves/{$awa->id}/photo")->assertForbidden();
        $this->asTenant($this->membre($ecole, 'etudiant'))->get("/api/eleves/{$awa->id}/photo")->assertForbidden();
        $this->asTenant($this->membre($this->ecole(), 'admin'))->get("/api/eleves/{$awa->id}/photo")->assertNotFound();
    }

    public function test_sans_photo_la_lecture_rend_404(): void
    {
        [$admin, $awa] = $this->eleveAvecDossier();

        $this->asTenant($admin)->get("/api/eleves/{$awa->id}/photo")->assertNotFound();
        $this->asTenant($admin)->getJson("/api/eleves/{$awa->id}/dossier")->assertJsonPath('data.dossier.photo_url', null);
    }

    public function test_retirer_la_photo(): void
    {
        [$admin, $awa] = $this->eleveAvecDossier();
        $this->deposer($admin, $awa, $this->image('png', 100, 100))->assertCreated();

        $retrait = $this->asTenant($admin)->deleteJson("/api/eleves/{$awa->id}/photo");

        $retrait->assertOk();
        $this->assertMatchesOpenApiResponse($retrait, '/eleves/{eleve}/photo', 'delete');
        self::assertSame([], Storage::disk('local')->allFiles());
        self::assertNull(DB::table('dossiers_eleves')->where('user_id', $awa->id)->value('photo_chemin'));
    }

    public function test_la_purge_du_compte_emporte_la_photo(): void
    {
        [$admin, $awa] = $this->eleveAvecDossier();
        $this->deposer($admin, $awa, $this->image('png', 100, 100))->assertCreated();

        User::query()->withoutGlobalScopes()->findOrFail($awa->id)->forceDelete();

        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_403_hors_de_la_gestion_et_en_ecole_klassci(): void
    {
        [, $awa, $ecole] = $this->eleveAvecDossier();
        $this->deposer($this->membre($ecole, 'enseignant'), $awa, $this->image('png', 100, 100))->assertForbidden();
        $this->deposer($awa, $awa, $this->image('png', 100, 100))->assertForbidden();

        $klassci = $this->ecole(InstitutionMode::Klassci);
        $this->deposer($this->membre($klassci, 'admin'), $this->membre($klassci, 'etudiant'), $this->image('png', 100, 100))
            ->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    // ───────────────────────── harnais

    /** @return array{0: User, 1: User, 2: Institution} l'admin, l'élève (avec dossier), l'école */
    private function eleveAvecDossier(): array
    {
        $ecole = $this->ecole();
        $admin = $this->membre($ecole, 'admin');
        $eleve = $this->membre($ecole, 'etudiant');
        $this->asTenant($admin)->putJson("/api/eleves/{$eleve->id}/dossier", ['nom' => 'Konan', 'prenoms' => 'Awa'])->assertCreated();

        return [$admin, $eleve, $ecole];
    }

    private function deposer(User $acteur, User $eleve, UploadedFile $photo): TestResponse
    {
        return $this->asTenant($acteur)->post("/api/eleves/{$eleve->id}/photo", ['photo' => $photo], ['Accept' => 'application/json']);
    }

    /** Une vraie image, encodée par GD, à déposer. */
    private function image(string $format, int $largeur, int $hauteur): UploadedFile
    {
        return UploadedFile::fake()->createWithContent("photo.{$format}", $this->octets($format, $largeur, $hauteur));
    }

    /** Les octets d'une vraie image, encodée par GD (`png` ou `jpeg`). */
    private function octets(string $format, int $largeur, int $hauteur): string
    {
        $gd = imagecreatetruecolor($largeur, $hauteur);
        imagefill($gd, 0, 0, (int) imagecolorallocate($gd, 30, 120, 200));
        ob_start();
        $format === 'png' ? imagepng($gd) : imagejpeg($gd);

        return (string) ob_get_clean();
    }

    private function enseigneDans(User $enseignant, Classe $classe): void
    {
        $matiere = $this->matiere(Institution::query()->findOrFail($classe->institution_id), 'Histoire');
        DB::table('classe_matiere')->insert([
            'classe_id' => $classe->getKey(), 'matiere_id' => $matiere->getKey(), 'enseignant_id' => $enseignant->getKey(),
            'institution_id' => $classe->institution_id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
