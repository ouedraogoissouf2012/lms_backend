<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Models\SeanceRecording;
use App\Services\Visio\Recording\RecordingMediaStorage;
use App\Services\Visio\Recording\SeanceRecordingRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * #469 — la purge doit effacer le MÉDIA, pas seulement les lignes.
 *
 * ## Le défaut que ces tests verrouillent
 *
 * `SeanceRecordingRetentionService::purge()` supprimait le chapitre et
 * l'enregistrement en base, et **ne touchait jamais au système de fichiers**.
 * `ChapterArtifactStorage::purgeChapter()` n'est appelé que depuis le pipeline de
 * conversion — jamais à la suppression — et aucun observer n'est branché sur
 * `Chapter`.
 *
 * Autrement dit : la rétention faisait disparaître la trace d'un enregistrement
 * tout en laissant la vidéo lisible sur le disque, à une URL toujours valide.
 *
 * Ce n'est pas un détail d'hygiène. Le dossier de conformité du projet est
 * explicite : un effacement qui laisse la donnée lisible **n'est pas un
 * effacement**. Une purge qui ne supprime que des lignes documente une
 * suppression qui n'a pas eu lieu — pire qu'une absence de purge, parce qu'elle
 * la fait croire faite.
 *
 * ## Portée
 *
 * Ces tests couvrent le média d'un **enregistrement**. L'orphelinage des
 * fichiers à la suppression d'un chapitre quelconque est un défaut plus large,
 * pré-existant, tracé en issue de suivi et hors périmètre de #469.
 *
 * @see \App\Services\Visio\Recording\SeanceRecordingRetentionService
 * @see \App\Services\Visio\Recording\RecordingMediaStorage
 */
final class SeanceRecordingMediaPurgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-31 12:00:00');
        Storage::fake(RecordingMediaStorage::DISK);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function retention(): SeanceRecordingRetentionService
    {
        return $this->app->make(SeanceRecordingRetentionService::class);
    }

    private function media(): RecordingMediaStorage
    {
        return $this->app->make(RecordingMediaStorage::class);
    }

    /**
     * Un enregistrement terminé il y a longtemps, avec son média sur le disque.
     *
     * @return array{SeanceRecording, string}
     */
    private function expiredRecordingWithMedia(): array
    {
        $recording = SeanceRecording::factory()->ready()->create([
            'processed_at' => Carbon::now()->subYears(2),
        ]);

        $source = sys_get_temp_dir().'/purge-src-'.bin2hex(random_bytes(6)).'.mp4';
        file_put_contents($source, 'octets-video');

        $relative = $this->media()->store($source, (int) $recording->id);
        self::assertIsString($relative, 'le média doit être stocké avant de tester sa purge');
        Storage::disk(RecordingMediaStorage::DISK)->assertExists($relative);

        @unlink($source);

        return [$recording, $relative];
    }

    /**
     * LE test de #469 : purger doit faire disparaître la vidéo du disque.
     *
     * Avant le correctif, il échoue — la ligne disparaît, le fichier reste.
     */
    public function test_purge_removes_the_media_from_disk(): void
    {
        [$recording, $relative] = $this->expiredRecordingWithMedia();

        $this->retention()->purge($recording, Carbon::now());

        Storage::disk(RecordingMediaStorage::DISK)->assertMissing($relative);
    }

    /** La suppression des lignes reste acquise : on ajoute, on ne remplace pas. */
    public function test_purge_still_removes_the_database_row(): void
    {
        [$recording] = $this->expiredRecordingWithMedia();

        $this->retention()->purge($recording, Carbon::now());

        $this->assertDatabaseMissing('seance_recordings', ['id' => $recording->id]);
    }

    /**
     * Un enregistrement NON éligible ne doit rien perdre — surtout pas son média.
     * Une purge trop zélée détruirait un cours encore dans sa durée de rétention.
     */
    public function test_media_of_a_recording_still_within_retention_is_kept(): void
    {
        [$recording, $relative] = $this->expiredRecordingWithMedia();
        $recording->update(['processed_at' => Carbon::now()]);

        $result = $this->retention()->purge($recording->fresh(), Carbon::now()->subYear());

        self::assertNull($result, 'un enregistrement non éligible ne doit pas être purgé');
        Storage::disk(RecordingMediaStorage::DISK)->assertExists($relative);
    }

    /**
     * Un enregistrement dont le rattachement avait échoué n'a pas de chapitre —
     * c'est précisément le cas où ranger le média sous le chapitre l'aurait rendu
     * inatteignable. Il doit quand même être effacé.
     */
    public function test_media_is_purged_even_without_an_attached_chapter(): void
    {
        [$recording, $relative] = $this->expiredRecordingWithMedia();
        self::assertNull($recording->chapter_id, 'ce cas exige un enregistrement sans chapitre');

        $this->retention()->purge($recording, Carbon::now());

        Storage::disk(RecordingMediaStorage::DISK)->assertMissing($relative);
    }
}
