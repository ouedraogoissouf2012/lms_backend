<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Visio\Recording;

use App\Services\Visio\Recording\RecordingMediaStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * #469 — le LMS s'approprie le média, donc il doit savoir l'effacer.
 *
 * ## Pourquoi ranger sous l'enregistrement et non sous le chapitre
 *
 * `ChapterArtifactStorage::storeVideo()` exige un identifiant de chapitre. Or au
 * moment de l'import, le chapitre **n'existe pas encore** : c'est
 * `SeanceRecordingAttachmentResolver` qui le crée, et il lui faut l'URL pour ça.
 *
 * Surtout, le rattachement peut échouer légitimement (`ambiguous_lesson`,
 * `lesson_not_found`). Un média rangé sous un chapitre qui n'a jamais existé
 * serait alors orphelin pour toujours. Rangé sous l'enregistrement, il reste
 * purgeable dans tous les cas.
 *
 * ## L'assertion qui compte
 *
 * `purge()` doit **réellement effacer**. Vérifier qu'une méthode a été appelée
 * ne prouve rien sur ce qui reste sur le disque : ces tests interrogent le
 * disque après coup.
 *
 * @see PRODUCTION_STANDARDS.md §1.2
 */
final class RecordingMediaStorageTest extends TestCase
{
    private const DISK = 'public';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::DISK);
    }

    private function storage(): RecordingMediaStorage
    {
        return $this->app->make(RecordingMediaStorage::class);
    }

    private function sourceFile(string $contents = 'octets-video'): string
    {
        $path = sys_get_temp_dir().'/jibri-src-'.bin2hex(random_bytes(6)).'.mp4';
        file_put_contents($path, $contents);

        return $path;
    }

    // -------------------------------------------------------------------- store

    public function test_stores_the_media_under_the_recording_identifier(): void
    {
        $relative = $this->storage()->store($this->sourceFile(), 42);

        $this->assertStringStartsWith('recordings/42/video/', $relative);
        Storage::disk(self::DISK)->assertExists($relative);
    }

    public function test_preserves_the_media_bytes(): void
    {
        $relative = $this->storage()->store($this->sourceFile('contenu-exact'), 7);

        $this->assertSame('contenu-exact', Storage::disk(self::DISK)->get($relative));
    }

    /**
     * Le nom de fichier ne doit pas être devinable : contrairement aux
     * diapositives (`slide_001.png`, dette #598 tracée), l'URL d'un enregistrement
     * ne doit pas s'énumérer à partir d'un identifiant séquentiel.
     */
    public function test_file_name_is_not_derived_from_the_recording_identifier(): void
    {
        $relative = $this->storage()->store($this->sourceFile(), 42);
        $fileName = basename($relative);

        $this->assertStringNotContainsString('42', $fileName);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{16,}\.mp4$/', $fileName);
    }

    public function test_two_media_of_the_same_recording_do_not_collide(): void
    {
        $first = $this->storage()->store($this->sourceFile('un'), 42);
        $second = $this->storage()->store($this->sourceFile('deux'), 42);

        $this->assertNotSame($first, $second);
        Storage::disk(self::DISK)->assertExists($first);
        Storage::disk(self::DISK)->assertExists($second);
    }

    public function test_returns_null_when_the_source_file_is_missing(): void
    {
        $this->assertNull($this->storage()->store('/chemin/qui/nexiste/pas.mp4', 42));
    }

    // ---------------------------------------------------------------------- url

    public function test_exposes_a_readable_url(): void
    {
        $relative = $this->storage()->store($this->sourceFile(), 42);
        $url = $this->storage()->url($relative);

        $this->assertStringContainsString($relative, $url);
        $this->assertNotFalse(filter_var($url, FILTER_VALIDATE_URL));
    }

    // -------------------------------------------------------------------- purge

    public function test_purge_really_removes_the_media_from_disk(): void
    {
        $relative = $this->storage()->store($this->sourceFile(), 42);
        Storage::disk(self::DISK)->assertExists($relative);

        $this->storage()->purge(42);

        Storage::disk(self::DISK)->assertMissing($relative);
    }

    public function test_purge_leaves_other_recordings_untouched(): void
    {
        $kept = $this->storage()->store($this->sourceFile(), 7);
        $this->storage()->store($this->sourceFile(), 42);

        $this->storage()->purge(42);

        Storage::disk(self::DISK)->assertExists($kept);
    }

    public function test_purge_of_an_unknown_recording_does_not_fail(): void
    {
        $this->storage()->purge(999);

        $this->assertTrue(true, 'purge() doit être idempotente : rien à effacer n\'est pas une erreur');
    }
}
