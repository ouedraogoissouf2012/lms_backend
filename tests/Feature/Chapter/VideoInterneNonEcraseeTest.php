<?php

declare(strict_types=1);

namespace Tests\Feature\Chapter;

use App\Enums\InstitutionMode;
use App\Models\Chapter;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Visio\Recording\RecordingMediaStorage;
use App\Services\Visio\Recording\RecordingVideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1287 — une modification de chapitre ne remplace plus le chemin d'une vidéo INTERNE.
 *
 * Le front lit les chapitres, où la vidéo interne (`jibri`, `upload`) est servie par une
 * URL SIGNÉE, puis renvoie ce `video_url` à chaque enregistrement (`buildChapterPayload`),
 * titre seul compris. L'écrire remplaçait le chemin du disque privé : la vidéo était
 * perdue. Le serveur ignore donc ce champ pour une vidéo interne, quelle que soit sa
 * valeur, et un lien externe (provider vide) reste modifiable.
 *
 * Les deux volets, vrai jeton porteur, et le parcours réel du front : lecture de la
 * liste, puis modification du payload lu.
 */
final class VideoInterneNonEcraseeTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const OCTETS = 4096;

    private const YOUTUBE = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Storage::fake(RecordingMediaStorage::DISK);
    }

    /** @return array<string, array{0: InstitutionMode, 1: string}> */
    public static function videosInternes(): array
    {
        $cas = [];
        foreach (['école autonome' => InstitutionMode::Standalone, 'école KLASSCI' => InstitutionMode::Klassci] as $volet => $mode) {
            foreach ([RecordingVideoService::JIBRI, RecordingVideoService::UPLOAD] as $provider) {
                $cas["{$provider}, {$volet}"] = [$mode, $provider];
            }
        }

        return $cas;
    }

    /** Le parcours du front : le titre change, la vidéo se lit toujours. */
    #[DataProvider('videosInternes')]
    public function test_le_front_renomme_le_chapitre_sans_perdre_sa_video(InstitutionMode $mode, string $provider): void
    {
        [$auteur, $chapitre, $chemin] = $this->chapitreInterne($mode, $provider);
        $lu = $this->chapitreLuParLeFront($auteur, $chapitre);
        self::assertIsString($lu['video_url']);
        self::assertStringContainsString('signature=', $lu['video_url']);

        $this->modifier($auteur, $chapitre, ['title' => 'Nouveau titre'] + $this->payloadDuFront($lu))->assertOk();

        $this->assertVideoIntacte($chapitre, $chemin, $provider);
        self::assertSame('Nouveau titre', $this->relu($chapitre)->title);
        $this->assertLaVideoSeLit($auteur, $chapitre);
    }

    /** @return array<string, array{0: InstitutionMode, 1: string, 2: string|null}> */
    public static function autresValeurs(): array
    {
        $cas = [];
        foreach (self::videosInternes() as $nom => [$mode, $provider]) {
            $cas["lien YouTube, {$nom}"] = [$mode, $provider, self::YOUTUBE];
            $cas["null, {$nom}"] = [$mode, $provider, null];
        }

        return $cas;
    }

    /** Aucune valeur du champ libre ne remplace le chemin : ni un autre lien, ni `null`. */
    #[DataProvider('autresValeurs')]
    public function test_aucune_valeur_du_champ_ne_remplace_la_video_interne(InstitutionMode $mode, string $provider, ?string $valeur): void
    {
        [$auteur, $chapitre, $chemin] = $this->chapitreInterne($mode, $provider);

        $this->modifier($auteur, $chapitre, ['video_url' => $valeur])->assertOk();

        $this->assertVideoIntacte($chapitre, $chemin, $provider);
        $this->assertLaVideoSeLit($auteur, $chapitre);
    }

    /** Verrou : une modification sans `video_url` ne touche pas la vidéo. */
    #[DataProvider('videosInternes')]
    public function test_une_modification_sans_lien_garde_la_video(InstitutionMode $mode, string $provider): void
    {
        [$auteur, $chapitre, $chemin] = $this->chapitreInterne($mode, $provider);

        $this->modifier($auteur, $chapitre, ['title' => 'Autre titre'])->assertOk();

        $this->assertVideoIntacte($chapitre, $chemin, $provider);
    }

    /** La réponse de la modification signe la vidéo interne, comme la lecture. */
    #[DataProvider('videosInternes')]
    public function test_la_reponse_ne_rend_jamais_le_chemin_prive(InstitutionMode $mode, string $provider): void
    {
        [$auteur, $chapitre, $chemin] = $this->chapitreInterne($mode, $provider);

        $rendu = $this->modifier($auteur, $chapitre, ['title' => 'Autre titre'])->assertOk()->json('data.video_url');

        self::assertIsString($rendu);
        self::assertNotSame($chemin, $rendu);
        self::assertStringContainsString('signature=', $rendu);
    }

    /** Verrou : un lien externe (provider vide) reste modifiable, et traverse intact. */
    #[DataProvider('volets')]
    public function test_un_lien_externe_reste_modifiable(InstitutionMode $mode): void
    {
        [$auteur, $chapitre] = $this->chapitreDe($mode, ['content_type' => 'video', 'video_provider' => null, 'video_url' => self::YOUTUBE]);
        $nouveau = 'https://vimeo.com/76979871';

        $this->modifier($auteur, $chapitre, ['video_url' => $nouveau])
            ->assertOk()
            ->assertJsonPath('data.video_url', $nouveau);

        self::assertSame($nouveau, $this->relu($chapitre)->video_url);
    }

    /** @return array<string, array{0: InstitutionMode}> */
    public static function volets(): array
    {
        return ['école autonome' => [InstitutionMode::Standalone], 'école KLASSCI' => [InstitutionMode::Klassci]];
    }

    /** @return array{0: User, 1: Chapter, 2: string} */
    private function chapitreInterne(InstitutionMode $mode, string $provider): array
    {
        $chemin = "chapters/video/{$provider}-".str_repeat('a', 32).'.mp4';
        Storage::disk(RecordingMediaStorage::DISK)->put($chemin, str_repeat('M', self::OCTETS));
        [$auteur, $chapitre] = $this->chapitreDe($mode, ['content_type' => 'video', 'video_provider' => $provider, 'video_url' => $chemin]);

        return [$auteur, $chapitre, $chemin];
    }

    /**
     * @param  array<string, mixed>  $attributs
     * @return array{0: User, 1: Chapter}
     */
    private function chapitreDe(InstitutionMode $mode, array $attributs): array
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

    /** @return array<string, mixed> le chapitre tel que la liste le rend au front */
    private function chapitreLuParLeFront(User $auteur, Chapter $chapitre): array
    {
        $liste = $this->asTenant($auteur)->getJson("/api/lessons/{$chapitre->lesson_id}/chapters")->assertOk()->json('data');
        self::assertIsArray($liste);
        $lu = collect($liste)->firstWhere('id', $chapitre->id);
        self::assertIsArray($lu);

        return $lu;
    }

    /**
     * Les champs de `buildChapterPayload` (frontend_lms, `src/utils/chapterManager.js`).
     *
     * @param  array<string, mixed>  $lu
     * @return array<string, mixed>
     */
    private function payloadDuFront(array $lu): array
    {
        return array_intersect_key($lu, array_flip(['title', 'content_type', 'content', 'video_url', 'external_link', 'autoplay_video', 'order']));
    }

    /** @param  array<string, mixed>  $donnees */
    private function modifier(User $auteur, Chapter $chapitre, array $donnees): TestResponse
    {
        return $this->asTenant($auteur)->putJson("/api/chapters/{$chapitre->id}", $donnees);
    }

    private function assertVideoIntacte(Chapter $chapitre, string $chemin, string $provider): void
    {
        $relu = $this->relu($chapitre);
        self::assertSame($chemin, $relu->video_url);
        self::assertSame($provider, $relu->video_provider);
    }

    /** La preuve depuis l'écran : une nouvelle lecture sert toujours le fichier. */
    private function assertLaVideoSeLit(User $auteur, Chapter $chapitre): void
    {
        $url = $this->chapitreLuParLeFront($auteur, $chapitre)['video_url'];
        self::assertIsString($url);

        $reponse = $this->get($url);
        $reponse->assertOk();
        self::assertSame(self::OCTETS, strlen($reponse->streamedContent()));
    }

    /** Relu hors de la portée d'établissement : le tenant d'une requête passée ne filtre rien ici. */
    private function relu(Chapter $chapitre): Chapter
    {
        return Chapter::query()->withoutGlobalScope('institution')->findOrFail($chapitre->id);
    }
}
