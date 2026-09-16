<?php

declare(strict_types=1);

namespace Tests\Feature\Visio;

use App\Models\Chapter;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * #824 — la vidéo d'un enregistrement ne s'obtient que par une URL signée.
 *
 * Calqué sur `ChapterSlideAccessTest` (#620), qui a résolu le même problème
 * pour les diapositives : le `.htaccess` refuse `slides/`, et l'accès unique
 * passe par une route signée. Les enregistrements, eux, étaient restés dans
 * l'angle mort — le fichier voisin le dit noir sur blanc :
 * « Les vidéos du même arbre restent publiques ».
 *
 * ## Pourquoi la signature vaut autorisation
 *
 * Une balise `<video>` n'envoie pas d'en-tête `Authorization`. L'autorisation
 * se fait donc à l'ÉMISSION — seul un utilisateur autorisé à lire le chapitre
 * reçoit une adresse signée — et la signature devient le jeton, exactement
 * comme pour les diapositives.
 *
 * ## Pourquoi 4 heures
 *
 * Les diapositives signent pour 60 minutes. Une vidéo se regarde en continu :
 * une signature expirée en cours de lecture couperait le cours. 4 h couvre une
 * séance longue sans laisser un lien fuité valable indéfiniment.
 */
final class RecordingVideoAccessTest extends TestCase
{
    use RefreshDatabase;

    /** 4 Kio : assez pour qu'une requête de plage porte un vrai sens. */
    private const MP4_BYTES = 4096;

    public function test_une_url_non_signee_est_refusee(): void
    {
        $chapter = $this->recordingChapter($this->institution());

        $this->get("/api/chapters/{$chapter->id}/video")->assertStatus(403);
    }

    public function test_l_url_signee_rend_les_octets_de_la_video(): void
    {
        $institution = $this->institution();
        $teacher = $this->user($institution);
        $chapter = $this->recordingChapter($institution, $teacher);

        $url = $this->actingWithToken($teacher)
            ->getJson("/api/chapters/{$chapter->id}")
            ->assertOk()
            ->json('data.video_url');

        self::assertIsString($url);
        self::assertStringContainsString('/video', $url);
        self::assertStringContainsString('signature=', $url);

        $response = $this->get($url);
        $response->assertOk();
        self::assertSame(self::MP4_BYTES, strlen($response->streamedContent()));
    }

    public function test_une_signature_alteree_est_refusee(): void
    {
        $chapter = $this->recordingChapter($this->institution());
        $url = URL::temporarySignedRoute(
            'chapters.video.show',
            now()->addHours(4),
            ['chapter' => $chapter->id],
        );

        $this->get($url.'x')->assertStatus(403);
    }

    public function test_une_signature_expiree_est_refusee(): void
    {
        $chapter = $this->recordingChapter($this->institution());
        $url = URL::temporarySignedRoute(
            'chapters.video.show',
            now()->addHours(4),
            ['chapter' => $chapter->id],
        );

        // Le lien qui fuite ne vaut pas eternellement : c'est tout l'interet
        // d'une signature TEMPORAIRE par rapport a un nom de fichier aleatoire.
        $this->travel(5)->hours();

        $this->get($url)->assertStatus(403);
    }

    public function test_un_tiers_ne_recoit_jamais_de_signature(): void
    {
        $chapter = $this->recordingChapter($this->institution());
        $outsider = $this->user($this->institution());

        $this->actingWithToken($outsider)
            ->getJson("/api/chapters/{$chapter->id}")
            ->assertStatus(404);
    }

    public function test_une_requete_de_plage_rend_206_et_le_bon_intervalle(): void
    {
        $chapter = $this->recordingChapter($this->institution());
        $url = URL::temporarySignedRoute(
            'chapters.video.show',
            now()->addHours(4),
            ['chapter' => $chapter->id],
        );

        // Sans plage, se deplacer dans une video d'une heure obligerait le
        // lecteur a la retelecharger depuis le debut.
        $response = $this->get($url, ['Range' => 'bytes=0-99']);

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 0-99/'.self::MP4_BYTES);
        $response->assertHeader('Accept-Ranges', 'bytes');
        self::assertSame(100, strlen($response->streamedContent()));
    }

    public function test_un_lien_externe_traverse_intact(): void
    {
        $institution = $this->institution();
        $teacher = $this->user($institution);
        $chapter = $this->chapter($institution, $teacher, [
            'content_type' => 'video',
            'video_provider' => 'youtube',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);

        // `video_url` sert AUSSI aux videos externes saisies par l'enseignant
        // (`v-model="chapter.video_url"` cote front). Signer sans discriminer
        // detruirait ces liens.
        $this->actingWithToken($teacher)
            ->getJson("/api/chapters/{$chapter->id}")
            ->assertOk()
            ->assertJsonPath('data.video_url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    }

    private function actingWithToken(User $user): static
    {
        return $this->withToken($user->createToken('test-824')->plainTextToken);
    }

    private function institution(): Institution
    {
        return Institution::factory()->create(['is_active' => true]);
    }

    private function user(Institution $institution): User
    {
        return User::factory()->for($institution)->create([
            'role' => 'enseignant',
            'last_klassci_sync' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function chapter(Institution $institution, User $teacher, array $attributes): Chapter
    {
        $lesson = Lesson::factory()->create(['institution_id' => $institution->id]);

        return Chapter::factory()->create([
            'lesson_id' => $lesson->id,
            'institution_id' => $institution->id,
            'enseignant_id' => $teacher->id,
            ...$attributes,
        ]);
    }

    private function recordingChapter(Institution $institution, ?User $teacher = null): Chapter
    {
        Storage::fake('local');
        $teacher ??= $this->user($institution);

        $path = 'recordings/7/video/'.str_repeat('a', 32).'.mp4';
        Storage::disk('local')->put($path, str_repeat('M', self::MP4_BYTES));

        return $this->chapter($institution, $teacher, [
            'content_type' => 'video',
            'video_provider' => 'jibri',
            'video_url' => $path,
        ]);
    }
}
