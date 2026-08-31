<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Enums\SeanceRecordingStatus;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\SeanceRecording;
use App\Services\Visio\Recording\RoomRecordingResolver;
use App\Services\Visio\SecureVisioRoomIdGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #469 — le pont entre ce que le fournisseur connaît et ce que le LMS identifie.
 *
 * ## Le problème que cette classe résout
 *
 * Jibri connaît le **salon**. Il ne connaît, et ne peut connaître, aucun
 * identifiant interne au LMS : `recording_id` est un entier de notre base. La
 * finalisation doit donc traverser `visio_room_id` → séance → enregistrement.
 *
 * ## Pourquoi refuser plutôt que choisir
 *
 * Deux enregistrements actifs sur un même salon signalent un état que personne
 * n'a prévu. En choisir un rattacherait potentiellement l'enregistrement d'un
 * cours à un autre. Refuser laisse le fichier intact et l'anomalie visible.
 *
 * ## Sur le multi-tenant
 *
 * Cette résolution s'exécute **sans tenant résolu** : la route du webhook est
 * authentifiée par HMAC, pas par jeton porteur, donc `ResolveInstitution` ne pose
 * rien et le scope global `BelongsToInstitution` ne filtre pas. C'est assumé —
 * mais il faut alors prouver que le cloisonnement tient par la donnée elle-même,
 * ce que fait le test d'isolation ci-dessous : `visio_room_id` porte 160 bits
 * d'entropie, un salon n'appartient donc qu'à une séance, d'un seul
 * établissement.
 *
 * @see \App\Services\Visio\SecureVisioRoomIdGenerator
 * @see PRODUCTION_STANDARDS.md §1.2 (isolation multi-tenant)
 */
final class RoomRecordingResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): RoomRecordingResolver
    {
        return $this->app->make(RoomRecordingResolver::class);
    }

    /**
     * @param  SeanceRecordingStatus  $status  statut de l'enregistrement créé
     */
    private function recordingInRoom(
        string $room,
        SeanceRecordingStatus $status = SeanceRecordingStatus::Recording,
        ?Institution $institution = null,
    ): SeanceRecording {
        $institution ??= Institution::factory()->create();

        $seance = Seance::factory()->create([
            'institution_id' => $institution->id,
            'visio_room_id' => $room,
            'visio_status' => 'active',
        ]);

        return SeanceRecording::factory()->create([
            'seance_id' => $seance->id,
            'institution_id' => $institution->id,
            'status' => $status,
        ]);
    }

    // ------------------------------------------------------------ chemin nominal

    public function test_resolves_the_active_recording_of_a_known_room(): void
    {
        $room = SecureVisioRoomIdGenerator::make();
        $expected = $this->recordingInRoom($room);

        $this->assertSame($expected->id, $this->resolver()->resolve($room)?->id);
    }

    /**
     * @return list<array{SeanceRecordingStatus}>
     */
    public static function activeStatuses(): array
    {
        return [
            'recording' => [SeanceRecordingStatus::Recording],
            'uploading' => [SeanceRecordingStatus::Uploading],
            'processing' => [SeanceRecordingStatus::Processing],
        ];
    }

    /**
     * Les trois statuts actifs doivent résoudre. `Processing` en particulier :
     * c'est l'état dans lequel `stop()` place l'enregistrement, donc l'état
     * NORMAL au moment où Jibri finalise.
     *
     * @dataProvider activeStatuses
     */
    public function test_resolves_every_active_status(SeanceRecordingStatus $status): void
    {
        $room = SecureVisioRoomIdGenerator::make();
        $expected = $this->recordingInRoom($room, $status);

        $this->assertSame($expected->id, $this->resolver()->resolve($room)?->id);
    }

    // ----------------------------------------------------------------- refus

    public function test_returns_null_for_an_unknown_room(): void
    {
        $this->recordingInRoom(SecureVisioRoomIdGenerator::make());

        $this->assertNull($this->resolver()->resolve(SecureVisioRoomIdGenerator::make()));
    }

    public function test_returns_null_for_an_empty_room(): void
    {
        $this->assertNull($this->resolver()->resolve(''));
    }

    /**
     * @return list<array{SeanceRecordingStatus}>
     */
    public static function inactiveStatuses(): array
    {
        return [
            'idle' => [SeanceRecordingStatus::Idle],
            'ready' => [SeanceRecordingStatus::Ready],
            'failed' => [SeanceRecordingStatus::Failed],
        ];
    }

    /**
     * Un enregistrement déjà `Ready` ne doit pas être re-résolu : une
     * notification tardive ou rejouée ne doit pas réécrire un cours déjà publié.
     *
     * @dataProvider inactiveStatuses
     */
    public function test_returns_null_when_the_recording_is_not_active(SeanceRecordingStatus $status): void
    {
        $room = SecureVisioRoomIdGenerator::make();
        $this->recordingInRoom($room, $status);

        $this->assertNull($this->resolver()->resolve($room));
    }

    /**
     * INVARIANT DE SCHÉMA — deux enregistrements actifs sur une même séance sont
     * **impossibles**, et c'est la base qui l'impose : un hook `saving` pose
     * `active_lock_key = 'seance:{id}'` tant que le statut est actif, `null`
     * ensuite, et la colonne porte un index UNIQUE
     * ({@see \App\Models\SeanceRecording::activeLockKeyForSeance()},
     * `2026_07_12_000001_create_seance_recordings_table.php:28`).
     *
     * Ce test l'épingle plutôt que de le supposer : si quelqu'un retirait ce
     * verrou, la voie « salon » du webhook deviendrait ambiguë sur une même
     * séance — et ce test le dirait avant la production.
     *
     * Le cas « plusieurs correspondances » reste donc atteignable uniquement
     * entre séances DIFFÉRENTES partageant un salon — couvert plus bas.
     */
    public function test_two_active_recordings_on_one_seance_are_refused_by_the_schema(): void
    {
        $seance = Seance::factory()->create([
            'visio_room_id' => SecureVisioRoomIdGenerator::make(),
        ]);
        SeanceRecording::factory()->create([
            'seance_id' => $seance->id,
            'institution_id' => $seance->institution_id,
            'status' => SeanceRecordingStatus::Recording,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        SeanceRecording::factory()->create([
            'seance_id' => $seance->id,
            'institution_id' => $seance->institution_id,
            'status' => SeanceRecordingStatus::Recording,
        ]);
    }

    /** Une séance sans visio (`visio_room_id` nul) ne doit jamais être atteinte. */
    public function test_a_null_room_never_matches_a_seance_without_visio(): void
    {
        $institution = Institution::factory()->create();
        $seance = Seance::factory()->create([
            'institution_id' => $institution->id,
            'visio_room_id' => null,
        ]);
        SeanceRecording::factory()->create([
            'seance_id' => $seance->id,
            'institution_id' => $institution->id,
            'status' => SeanceRecordingStatus::Recording,
        ]);

        $this->assertNull($this->resolver()->resolve(''));
        $this->assertNull($this->resolver()->resolve('0'));
    }

    // ------------------------------------------------------------- isolation

    /**
     * ISOLATION — deux établissements, chacun sa séance et son enregistrement.
     * Le salon de l'un ne doit JAMAIS résoudre vers l'enregistrement de l'autre.
     *
     * Le service tourne sans tenant résolu (webhook HMAC) : c'est donc la donnée
     * qui cloisonne, et ce test est la seule preuve que ça tient.
     */
    public function test_a_room_never_resolves_across_institutions(): void
    {
        $roomA = SecureVisioRoomIdGenerator::make();
        $roomB = SecureVisioRoomIdGenerator::make();

        $recordingA = $this->recordingInRoom($roomA, institution: Institution::factory()->create());
        $recordingB = $this->recordingInRoom($roomB, institution: Institution::factory()->create());

        $resolvedA = $this->resolver()->resolve($roomA);
        $resolvedB = $this->resolver()->resolve($roomB);

        $this->assertSame($recordingA->id, $resolvedA?->id);
        $this->assertSame($recordingB->id, $resolvedB?->id);
        $this->assertNotSame(
            $resolvedA?->institution_id,
            $resolvedB?->institution_id,
            'chaque salon doit rester dans son établissement',
        );
    }

    /**
     * Cas limite le plus hostile : deux établissements partageant PAR ACCIDENT
     * le même identifiant de salon. Impossible en pratique (160 bits
     * d'entropie), mais si cela arrivait, résoudre arbitrairement livrerait le
     * cours d'une école à l'autre. On refuse.
     */
    public function test_a_room_shared_by_two_institutions_is_refused(): void
    {
        $room = SecureVisioRoomIdGenerator::make();
        $this->recordingInRoom($room, institution: Institution::factory()->create());
        $this->recordingInRoom($room, institution: Institution::factory()->create());

        $this->assertNull(
            $this->resolver()->resolve($room),
            'une collision de salon entre établissements ne doit jamais être tranchée au hasard',
        );
    }
}
