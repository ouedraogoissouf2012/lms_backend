<?php

declare(strict_types=1);

namespace Tests\Feature\Chapter;

use App\Enums\InstitutionMode;
use App\Models\Chapter;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Services\ConvertApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1330 — aucune réponse chapitre ne rend un chemin du stockage.
 *
 * Le téléversement synchrone (`POST /chapters/{id}/upload`, sans `?async`) rendait le chapitre
 * brut : les chemins des diapositives au lieu des URL signées de `index` et `show`, et le
 * document source. Et `file_original_path` / `file_converted_path` sortaient de TOUTES les
 * réponses chapitre, alors que seul le serveur les lit.
 *
 * Les deux volets, vrai jeton porteur. La conversion passe par ConvertAPI, seul double : le
 * stockage, la validation et la signature sont les vrais.
 *
 * Le 202 asynchrone (un suivi, pas un chapitre) reste figé par `AsyncChapterConversionTest`.
 */
final class TeleversementSigneTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const PNG = "\x89PNG\r\n\x1a\nimage";

    private const SOURCE = 'chapters/originaux/source-aaaaaaaa.pdf';

    private const CONVERTI = 'chapters/pdf/converti-aaaaaaaa.pdf';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Storage::fake('local');
        Storage::fake('public');
    }

    /** @return array<string, array{0: InstitutionMode, 1: string}> */
    public static function documents(): array
    {
        $cas = [];
        foreach (self::volets() as $volet => [$mode]) {
            $cas["pdf, {$volet}"] = [$mode, 'pdf'];
            $cas["pptx, {$volet}"] = [$mode, 'pptx'];
        }

        return $cas;
    }

    /** Les diapositives rendues sont des URL signées, et elles servent l'image. */
    #[DataProvider('documents')]
    public function test_le_televersement_synchrone_signe_les_diapositives(InstitutionMode $mode, string $extension): void
    {
        [$auteur, $chapitre] = $this->chapitreDe($mode);
        $this->conversionEnDeuxDiapositives($extension);

        $diapositives = $this->televerser($auteur, $chapitre, $extension)->assertOk()->json('data.slides_images');

        self::assertIsArray($diapositives);
        self::assertCount(2, $diapositives);
        foreach ($diapositives as $url) {
            self::assertIsString($url);
            self::assertStringContainsString("/chapters/{$chapitre->id}/slides/", $url);
            self::assertStringContainsString('signature=', $url);
        }
        $premiere = $diapositives[0];
        self::assertIsString($premiere);
        $this->flushHeaders();
        self::assertSame(self::PNG, $this->get($premiere)->assertOk()->streamedContent());
    }

    /** Aucun chemin enregistré par la conversion n'apparaît dans le corps. */
    #[DataProvider('documents')]
    public function test_la_reponse_du_televersement_ne_porte_aucun_chemin(InstitutionMode $mode, string $extension): void
    {
        [$auteur, $chapitre] = $this->chapitreDe($mode);
        $this->conversionEnDeuxDiapositives($extension);

        $reponse = $this->televerser($auteur, $chapitre, $extension)->assertOk();

        $relu = $this->relu($chapitre);
        $chemins = [...(array) $relu->slides_images, $relu->file_original_path, $relu->file_converted_path];
        self::assertCount(4, array_filter($chemins, 'is_string'), 'La conversion a bien écrit ses chemins.');
        $this->assertAucunChemin($reponse, $chemins);
    }

    /** @return array<string, array{0: InstitutionMode, 1: string}> */
    public static function reponsesChapitre(): array
    {
        $cas = [];
        foreach (self::volets() as $volet => [$mode]) {
            foreach (['index', 'show', 'update'] as $reponse) {
                $cas["{$reponse}, {$volet}"] = [$mode, $reponse];
            }
        }

        return $cas;
    }

    /** `index`, `show` et `update` ne rendent plus les deux chemins du stockage. */
    #[DataProvider('reponsesChapitre')]
    public function test_aucune_reponse_chapitre_ne_rend_les_chemins_de_fichier(InstitutionMode $mode, string $reponse): void
    {
        [$auteur, $chapitre] = $this->chapitreDe($mode, ['file_original_path' => self::SOURCE, 'file_converted_path' => self::CONVERTI]);

        $corps = match ($reponse) {
            'index' => $this->asTenant($auteur)->getJson("/api/lessons/{$chapitre->lesson_id}/chapters"),
            'show' => $this->asTenant($auteur)->getJson("/api/chapters/{$chapitre->id}"),
            'update' => $this->asTenant($auteur)->putJson("/api/chapters/{$chapitre->id}", ['title' => 'Autre titre']),
        };

        $corps->assertOk();
        $this->assertAucunChemin($corps, [self::SOURCE, self::CONVERTI]);
        self::assertSame(self::SOURCE, $this->relu($chapitre)->file_original_path, 'Le serveur garde le chemin.');
    }

    /**
     * Verrou : la création ne rend pas ces champs non plus. Vert sur la base aussi, par
     * construction : `store` n'écrit jamais ces colonnes. C'est `$hidden` qui le garantit
     * le jour où elle les écrira.
     */
    #[DataProvider('volets')]
    public function test_la_creation_ne_rend_pas_les_chemins_de_fichier(InstitutionMode $mode): void
    {
        [$auteur, $chapitre] = $this->chapitreDe($mode);

        $this->asTenant($auteur)->postJson("/api/lessons/{$chapitre->lesson_id}/chapters", ['titre' => 'Nouveau chapitre'])
            ->assertCreated()
            ->assertJsonMissingPath('data.file_original_path')
            ->assertJsonMissingPath('data.file_converted_path');
    }

    /** @return array<string, array{0: InstitutionMode}> */
    public static function volets(): array
    {
        return ['école autonome' => [InstitutionMode::Standalone], 'école KLASSCI' => [InstitutionMode::Klassci]];
    }

    /** ConvertAPI rend deux diapositives, écrites sur le disque qui les sert. */
    private function conversionEnDeuxDiapositives(string $extension): void
    {
        $methode = $extension === 'pdf' ? 'convertPdfToImages' : 'convertPowerPointToImages';

        $this->mock(ConvertApiService::class, function (MockInterface $mock) use ($methode): void {
            $mock->shouldReceive($methode)->once()->andReturnUsing(static function (string $source, string $dossier): array {
                $images = ["{$dossier}/slide_001.png", "{$dossier}/slide_002.png"];
                foreach ($images as $image) {
                    Storage::disk('public')->put($image, self::PNG);
                }

                return $images;
            });
        });
    }

    private function televerser(User $auteur, Chapter $chapitre, string $extension): TestResponse
    {
        $mime = $extension === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

        return $this->asTenant($auteur)->postJson("/api/chapters/{$chapitre->id}/upload", [
            'file' => UploadedFile::fake()->create("cours.{$extension}", 40, $mime),
        ]);
    }

    /**
     * Cherche dans le JSON DÉCODÉ : le corps brut échappe les `/` en `\/`, et un chemin
     * cherché tel quel n'y serait jamais trouvé, même présent.
     *
     * @param  array<int, mixed>  $chemins
     */
    private function assertAucunChemin(TestResponse $reponse, array $chemins): void
    {
        $corps = (string) json_encode($reponse->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach (array_filter($chemins, 'is_string') as $chemin) {
            self::assertStringNotContainsString($chemin, $corps);
        }
    }

    /**
     * @param  array<string, mixed>  $attributs
     * @return array{0: User, 1: Chapter}
     */
    private function chapitreDe(InstitutionMode $mode, array $attributs = []): array
    {
        $ecole = Institution::factory()->create(['mode' => $mode, 'is_active' => true]);
        $auteur = User::factory()->for($ecole)->create(['role' => 'enseignant', 'last_klassci_sync' => now()]);
        $lecon = Lesson::factory()->create(['institution_id' => $ecole->id, 'enseignant_id' => $auteur->id]);

        return [$auteur, Chapter::factory()->create([
            'lesson_id' => $lecon->id,
            'institution_id' => $ecole->id,
            'enseignant_id' => $auteur->id,
            ...$attributs,
        ])];
    }

    /** Relu hors de la portée d'établissement : le tenant d'une requête passée ne filtre rien ici. */
    private function relu(Chapter $chapitre): Chapter
    {
        return Chapter::query()->withoutGlobalScope('institution')->findOrFail($chapitre->id);
    }
}
