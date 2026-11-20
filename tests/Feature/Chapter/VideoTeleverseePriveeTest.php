<?php

declare(strict_types=1);

namespace Tests\Feature\Chapter;

use App\Enums\InstitutionMode;
use App\Models\Chapter;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Chapter\ChapterConversionDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #857 — une vidéo téléversée sur un chapitre se range sur le disque PRIVÉ et ne se lit que
 * par l'URL signée de #824, avec son vrai type.
 *
 * Avant, `ChapterArtifactStorage::storeVideo()` l'écrivait sur le disque public, servi sans
 * authentification (`video_url = /storage/…`) : la classe de défaut de #824.
 *
 * Le téléversement vidéo n'est PAS ouvert : `UploadFileRequest` refuse tout format vidéo, et
 * l'ouvrir est une décision du user. Le chemin est donc éprouvé par le répartiteur directement,
 * pour qu'il soit sûr d'avance ; un test HTTP fige le refus d'aujourd'hui.
 */
final class VideoTeleverseePriveeTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const OCTETS = 4096;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Storage::fake('local');
        Storage::fake('public');
    }

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école autonome' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    /** Aujourd'hui, l'API refuse toute vidéo : l'ouvrir est une décision du user. */
    #[DataProvider('volets')]
    public function test_le_televersement_d_une_video_est_refuse_aujourd_hui(InstitutionMode $mode): void
    {
        [$enseignant, $chapitre] = $this->monter($mode);

        $this->asTenant($enseignant)
            ->postJson("/api/chapters/{$chapitre->id}/upload", ['file' => $this->video('cours.mp4', 'video/mp4')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    #[DataProvider('volets')]
    public function test_le_repartiteur_range_la_video_sur_le_disque_prive(InstitutionMode $mode): void
    {
        [, $chapitre] = $this->monter($mode);

        $this->app->make(ChapterConversionDispatcher::class)->dispatch($chapitre, $this->video('cours.mp4', 'video/mp4'));

        $chapitre->refresh();
        self::assertSame('upload', $chapitre->video_provider);
        self::assertSame($chapitre->file_original_path, $chapitre->video_url, 'video_url porte le chemin relatif, jamais /storage/.');
        Storage::disk('local')->assertExists((string) $chapitre->video_url);
        self::assertSame([], Storage::disk('public')->allFiles(), 'Rien sur le disque public.');
    }

    #[DataProvider('volets')]
    public function test_la_video_televersee_se_lit_par_une_url_signee(InstitutionMode $mode): void
    {
        [$enseignant, $chapitre] = $this->monter($mode);
        $this->app->make(ChapterConversionDispatcher::class)->dispatch($chapitre, $this->video('cours.mp4', 'video/mp4'));

        $url = $this->asTenant($enseignant)->getJson("/api/chapters/{$chapitre->id}")->assertOk()->json('data.video_url');

        self::assertIsString($url);
        self::assertStringContainsString("/chapters/{$chapitre->id}/video", $url);
        self::assertStringContainsString('signature=', $url);
        $this->flushHeaders();
        $reponse = $this->get($url)->assertOk();
        self::assertSame(self::OCTETS, strlen((string) $reponse->streamedContent()));
        $this->get("/api/chapters/{$chapitre->id}/video")->assertForbidden();
    }

    /** Le vrai type : une vidéo webm n'est pas servie comme du mp4. */
    public function test_le_vrai_type_de_la_video_est_servi(): void
    {
        [$enseignant, $chapitre] = $this->monter(InstitutionMode::Standalone);
        $this->app->make(ChapterConversionDispatcher::class)->dispatch($chapitre, $this->video('cours.webm', 'video/webm'));

        $url = $this->asTenant($enseignant)->getJson("/api/chapters/{$chapitre->id}")->assertOk()->json('data.video_url');

        $this->flushHeaders();
        $this->get((string) $url)->assertOk()->assertHeader('Content-Type', 'video/webm');
    }

    /** @return array{0: User, 1: Chapter} */
    private function monter(InstitutionMode $mode): array
    {
        $ecole = Institution::factory()->create(['mode' => $mode, 'is_active' => true]);
        $enseignant = User::factory()->for($ecole)->create(['role' => 'enseignant', 'last_klassci_sync' => now()]);
        // La leçon et le chapitre ont le même auteur : un enseignant ne lit que ses leçons (#1167).
        $lecon = Lesson::factory()->create(['institution_id' => $ecole->id, 'enseignant_id' => $enseignant->id]);
        $chapitre = Chapter::factory()->create([
            'lesson_id' => $lecon->id,
            'institution_id' => $ecole->id,
            'enseignant_id' => $enseignant->id,
        ]);

        return [$enseignant, $chapitre];
    }

    private function video(string $nom, string $type): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nom, str_repeat("\0", self::OCTETS))->mimeType($type);
    }
}
