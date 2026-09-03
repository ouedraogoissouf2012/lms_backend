<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SeanceRecordingStatus;
use App\Models\Seance;
use App\Models\SeanceRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #514 — filet de sécurité. Sans webhook provider (cf. #204), un
 * enregistrement arrêté reste bloqué en `Processing` indéfiniment (le finaliseur
 * ProcessSeanceRecordingReady n'est jamais dispatché). La commande
 * `recordings:fail-stale` le passe à `Failed` au-delà du délai
 * (`config('recordings.stale_processing_minutes')`), ce qui reflète un état
 * honnête ET libère le verrou actif (un nouvel enregistrement redevient possible).
 *
 * @see app/Console/Commands/FailStaleRecordings.php
 * @see app/Services/Visio/Recording/StaleRecordingFailer.php
 */
final class FailStaleRecordingsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['recordings.stale_processing_minutes' => 30]);
    }

    public function test_fails_recording_stuck_in_processing_beyond_threshold(): void
    {
        $stale = SeanceRecording::factory()->processing()->create([
            'stopped_at' => now()->subMinutes(45),
        ]);
        self::assertNotNull($stale->active_lock_key, 'le verrou est posé tant que le statut est actif');

        $this->artisan('recordings:fail-stale')->assertSuccessful();

        $stale->refresh();
        self::assertSame(SeanceRecordingStatus::Failed, $stale->status);
        self::assertNotNull($stale->error_message);
        self::assertNotNull($stale->processed_at);
        self::assertNull($stale->active_lock_key, 'le verrou actif est libéré → nouvel enregistrement possible');
    }

    public function test_leaves_recently_stopped_processing_untouched(): void
    {
        $recent = SeanceRecording::factory()->processing()->create([
            'stopped_at' => now()->subMinutes(5),
        ]);

        $this->artisan('recordings:fail-stale')->assertSuccessful();

        self::assertSame(SeanceRecordingStatus::Processing, $recent->refresh()->status);
    }

    public function test_leaves_active_recording_and_ready_untouched(): void
    {
        $recording = SeanceRecording::factory()->recording()->create();
        $ready = SeanceRecording::factory()->ready()->create([
            'stopped_at' => now()->subDay(),
        ]);

        $this->artisan('recordings:fail-stale')->assertSuccessful();

        self::assertSame(SeanceRecordingStatus::Recording, $recording->refresh()->status);
        self::assertSame(SeanceRecordingStatus::Ready, $ready->refresh()->status);
    }

    public function test_respects_configurable_threshold(): void
    {
        config(['recordings.stale_processing_minutes' => 120]);

        $stoppedNinetyMinAgo = SeanceRecording::factory()->processing()->create([
            'stopped_at' => now()->subMinutes(90),
        ]);

        // 90 min < seuil de 120 min → doit rester Processing.
        $this->artisan('recordings:fail-stale')->assertSuccessful();

        self::assertSame(SeanceRecordingStatus::Processing, $stoppedNinetyMinAgo->refresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // #680 — `Recording` n'était refermé par RIEN.
    //
    // `Recording` est un statut ACTIF porteur de `active_lock_key`, posé par
    // `start()`, mais le balayage ne couvrait que `Processing`. Un enseignant qui
    // ferme son onglet sans cliquer « Arrêter » laissait la ligne ouverte
    // indéfiniment : verrou jamais rendu, écran affirmant « en cours » à jamais.
    // Constaté en production le 2026-09-02 (seance_recordings#1).
    //
    // La péremption est ancrée sur `seances.visio_ended_at` et NON sur
    // `visio_status` : c'est une date, donc elle autorise un délai de grâce
    // pendant lequel Jibri finalise encore son fichier.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_fails_recording_when_visio_ended_beyond_grace(): void
    {
        $seance = Seance::factory()->visioEnded()->create([
            'visio_ended_at' => now()->subMinutes(45),
        ]);
        $recording = SeanceRecording::factory()->recording()->forSeance($seance)->create();
        self::assertNotNull($recording->active_lock_key, 'le verrou est posé tant que le statut est actif');

        $this->artisan('recordings:fail-stale')->assertSuccessful();

        $recording->refresh();
        self::assertSame(SeanceRecordingStatus::Failed, $recording->status);
        self::assertNotNull($recording->error_message);
        self::assertNull($recording->active_lock_key, 'le verrou est rendu → un nouvel enregistrement redevient possible');
    }

    /**
     * La course que le délai de grâce protège : l'enseignant termine la visio,
     * Jibri finalise encore son fichier et le webhook n'est pas arrivé. Marquer
     * `Failed` ici détruirait un enregistrement parfaitement valide.
     */
    public function test_leaves_recording_untouched_within_grace_after_visio_end(): void
    {
        $seance = Seance::factory()->visioEnded()->create([
            'visio_ended_at' => now()->subMinutes(2),
        ]);
        $recording = SeanceRecording::factory()->recording()->forSeance($seance)->create();

        $this->artisan('recordings:fail-stale')->assertSuccessful();

        self::assertSame(SeanceRecordingStatus::Recording, $recording->refresh()->status);
    }

    /**
     * Le plafond absolu : filet de dernier recours si `visio_ended_at` reste nul
     * parce que la visio elle-même est restée bloquée en `active`. C'est
     * exactement le cas de production du 2026-09-02.
     */
    public function test_fails_recording_exceeding_absolute_maximum_duration(): void
    {
        $seance = Seance::factory()->visioActive()->create();
        $recording = SeanceRecording::factory()->recording()->forSeance($seance)->create([
            'started_at' => now()->subHours(9),
        ]);

        $this->artisan('recordings:fail-stale')->assertSuccessful();

        $recording->refresh();
        self::assertSame(SeanceRecordingStatus::Failed, $recording->status);
        self::assertNull($recording->active_lock_key);
    }

    /**
     * LE PIÈGE de #680 : réutiliser le délai de grâce de `Processing` (30 min)
     * couperait tout cours de plus d'une demi-heure. Un enregistrement long mais
     * borné, sur une visio toujours active, ne doit JAMAIS être touché.
     */
    public function test_leaves_long_running_recording_untouched_while_visio_is_active(): void
    {
        $seance = Seance::factory()->visioActive()->create();
        $recording = SeanceRecording::factory()->recording()->forSeance($seance)->create([
            'started_at' => now()->subHours(2),
        ]);

        $this->artisan('recordings:fail-stale')->assertSuccessful();

        self::assertSame(
            SeanceRecordingStatus::Recording,
            $recording->refresh()->status,
            'un cours de 2 h est normal : le seuil de Processing ne doit pas s\'appliquer ici',
        );
    }

    /**
     * La passe `Recording` fait une JOINTURE, contrairement à celle de
     * `Processing`. `chunkById` doit donc désambiguïser la colonne de curseur,
     * sans quoi il boucle ou saute des lignes — un défaut invisible tant qu'un
     * test ne dépasse pas la taille de lot.
     */
    public function test_sweeps_every_row_across_several_chunks(): void
    {
        config(['recordings.purge_chunk_size' => 2]);

        $recordings = [];
        for ($i = 0; $i < 7; $i++) {
            $seance = Seance::factory()->visioEnded()->create([
                'visio_ended_at' => now()->subMinutes(45),
            ]);
            $recordings[] = SeanceRecording::factory()->recording()->forSeance($seance)->create();
        }

        $this->artisan('recordings:fail-stale')->assertSuccessful();

        foreach ($recordings as $index => $recording) {
            self::assertSame(
                SeanceRecordingStatus::Failed,
                $recording->refresh()->status,
                "la ligne #{$index} a été sautée par le découpage en lots",
            );
            self::assertNull($recording->active_lock_key);
        }
    }

    public function test_recording_thresholds_are_independent_from_the_processing_one(): void
    {
        // Un seuil `Processing` très permissif ne doit pas retenir un
        // enregistrement dont la visio est terminée depuis longtemps.
        config([
            'recordings.stale_processing_minutes' => 100_000,
            'recordings.stale_recording_grace_minutes' => 10,
        ]);

        $seance = Seance::factory()->visioEnded()->create([
            'visio_ended_at' => now()->subMinutes(30),
        ]);
        $recording = SeanceRecording::factory()->recording()->forSeance($seance)->create();

        $this->artisan('recordings:fail-stale')->assertSuccessful();

        self::assertSame(SeanceRecordingStatus::Failed, $recording->refresh()->status);
    }
}
