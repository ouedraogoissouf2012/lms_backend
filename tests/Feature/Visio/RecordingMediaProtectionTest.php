<?php

declare(strict_types=1);

namespace Tests\Feature\Visio;

use App\Models\Chapter;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Visio\Recording\RecordingMediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * #824 — l'enregistrement d'une séance ne se télécharge pas sans autorisation.
 *
 * ## Le défaut que ce test fige
 *
 * `RecordingMediaStorage::DISK` valait `public`, et `storage/app/public` est
 * servi par le serveur web sans traverser Laravel : le `.htaccess` du dossier
 * porte `Require all granted`. Quiconque obtenait l'URL — journal, historique
 * de navigation, partage, capture réseau — téléchargeait la vidéo d'un cours
 * entier, sans compte et sans trace.
 *
 * Le docblock de `RecordingMediaStorage` défendait ce choix par « `<video src>`
 * ne s'authentifie pas ». C'est exact, et c'est précisément ce que la route
 * signée résout : `ChapterSlideService` le fait déjà pour `<img>` depuis #689.
 * L'argument ne tenait donc plus.
 *
 * Le nom de fichier aléatoire n'est pas une protection : il rend l'URL
 * indevinable, pas inaccessible. Une URL qui fuite reste valable pour toujours.
 */
final class RecordingMediaProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_media_ne_vit_pas_sur_un_disque_servi_publiquement(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $relative = app(RecordingMediaStorage::class)->store($this->sourceFile(), 42);

        self::assertNotNull($relative, 'le média doit être copié');

        // Le coeur de #824 : rien de tout cela ne doit atterrir sous un chemin
        // que le serveur web sert directement.
        Storage::disk('public')->assertMissing($relative);
        Storage::disk('local')->assertExists($relative);
    }

    public function test_la_migration_deplace_le_media_deja_importe(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $chemin = 'recordings/3/video/deadbeef.mp4';
        Storage::disk('public')->put($chemin, 'octets-de-la-video');
        $chapitre = $this->chapitreJibri('https://apilms.klassci.com/storage/'.$chemin);

        $this->migration()->up();

        // Le correctif ne vaudrait rien s'il ne protegeait que les imports a
        // venir : la video deja importee est sur le disque public.
        Storage::disk('public')->assertMissing($chemin);
        Storage::disk('local')->assertExists($chemin);
        self::assertSame('octets-de-la-video', Storage::disk('local')->get($chemin));
        self::assertSame($chemin, $chapitre->fresh()?->video_url);
    }

    public function test_la_migration_ne_touche_pas_un_lien_externe(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $externe = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $chapitre = $this->chapitreJibri($externe);
        $chapitre->update(['video_provider' => 'youtube']);

        $this->migration()->up();

        self::assertSame($externe, $chapitre->fresh()?->video_url);
    }

    public function test_la_migration_est_idempotente(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $chemin = 'recordings/3/video/deadbeef.mp4';
        Storage::disk('public')->put($chemin, 'octets');
        $chapitre = $this->chapitreJibri('https://apilms.klassci.com/storage/'.$chemin);

        $this->migration()->up();
        $this->migration()->up();

        Storage::disk('local')->assertExists($chemin);
        self::assertSame($chemin, $chapitre->fresh()?->video_url);
    }

    private function migration(): object
    {
        return require database_path(
            'migrations/2026_09_16_000001_move_recording_media_to_private_disk.php'
        );
    }

    private function chapitreJibri(string $videoUrl): Chapter
    {
        $institution = Institution::factory()->create(['is_active' => true]);
        $teacher = User::factory()->for($institution)->create([
            'role' => 'enseignant',
            'last_klassci_sync' => now(),
        ]);
        $lesson = Lesson::factory()->create(['institution_id' => $institution->id]);

        return Chapter::factory()->create([
            'lesson_id' => $lesson->id,
            'institution_id' => $institution->id,
            'enseignant_id' => $teacher->id,
            'content_type' => 'video',
            'video_provider' => 'jibri',
            'video_url' => $videoUrl,
        ]);
    }

    /** Un vrai fichier lisible : `store()` refuse tout ce qui ne l'est pas. */
    private function sourceFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rec').'.mp4';
        file_put_contents($path, str_repeat('0', 2048));

        return $path;
    }
}
