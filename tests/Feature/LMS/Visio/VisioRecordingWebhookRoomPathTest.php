<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Enums\SeanceRecordingStatus;
use App\Jobs\ImportJibriRecordingMedia;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\SeanceRecording;
use App\Services\Visio\SecureVisioRoomIdGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * #469 — la voie « salon » du webhook, pour un Jibri auto-hébergé.
 *
 * ## Pourquoi une seconde voie plutôt qu'un second point d'entrée
 *
 * Le contrat historique (`recording_id` + `url`) suppose un fournisseur qui
 * héberge le média et connaît nos identifiants — le modèle JaaS. Un Jibri
 * auto-hébergé ne connaît ni l'un ni l'autre : il connaît le **salon** et le
 * répertoire de sa session.
 *
 * Les deux voies partagent l'authentification, l'anti-rejeu et l'enveloppe de
 * réponse. Les séparer en deux routes aurait dupliqué tout cela, et doublé la
 * surface à auditer pour un gain nul.
 *
 * ## Ce que ces tests verrouillent
 *
 * La voie historique reste **intacte** : c'est
 * {@see VisioRecordingWebhookTest}, exécuté sans modification, qui le prouve.
 * Ici on couvre la voie nouvelle, et surtout ses refus.
 *
 * @see \App\Services\Visio\Recording\SeanceRecordingWebhookService
 */
final class VisioRecordingWebhookRoomPathTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret-469';

    private const SESSION = '00e7571b-7204-4ecb-8cab-7fb84b57b916';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.visio.webhook_secret' => self::SECRET]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, string>
     */
    private function sign(array $body, string $nonce): array
    {
        $timestamp = (string) time();
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        return [
            'X-Visio-Signature' => 'sha256='.hash_hmac('sha256', $timestamp."\n".$nonce."\n".$raw, self::SECRET),
            'X-Visio-Timestamp' => $timestamp,
            'X-Visio-Nonce' => $nonce,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function signedPost(array $body, string $nonce = 'nonce-room'): TestResponse
    {
        return $this->postJson('/api/webhooks/visio/recording-ready', $body, $this->sign($body, $nonce));
    }

    private function recordingInRoom(string $room): SeanceRecording
    {
        $institution = Institution::factory()->create();
        $seance = Seance::factory()->create([
            'institution_id' => $institution->id,
            'visio_room_id' => $room,
        ]);

        return SeanceRecording::factory()->forSeance($seance)->create([
            'status' => SeanceRecordingStatus::Processing,
        ]);
    }

    // ------------------------------------------------------------ chemin nominal

    public function test_a_signed_room_notification_queues_the_import(): void
    {
        Queue::fake();
        $room = SecureVisioRoomIdGenerator::make();
        $this->recordingInRoom($room);

        $this->signedPost(['room' => $room, 'session' => self::SESSION])->assertStatus(202);

        Queue::assertPushedOn('low', ImportJibriRecordingMedia::class);
    }

    public function test_the_title_is_carried_through(): void
    {
        Queue::fake();
        $room = SecureVisioRoomIdGenerator::make();
        $this->recordingInRoom($room);

        $this->signedPost([
            'room' => $room,
            'session' => self::SESSION,
            'title' => 'Cours du 31 août',
        ])->assertStatus(202);

        Queue::assertPushed(ImportJibriRecordingMedia::class);
    }

    // -------------------------------------------------------------------- refus

    public function test_an_unknown_room_is_refused_without_queuing_anything(): void
    {
        Queue::fake();

        $this->signedPost([
            'room' => SecureVisioRoomIdGenerator::make(),
            'session' => self::SESSION,
        ])->assertStatus(404);

        Queue::assertNothingPushed();
    }

    /**
     * @return list<array{string}>
     */
    public static function malformedSessions(): array
    {
        return [
            'traversée' => ['../../../etc'],
            'vide' => [''],
            'pas un uuid' => ['session-42'],
            'majuscules' => ['00E7571B-7204-4ECB-8CAB-7FB84B57B916'],
        ];
    }

    /**
     * Le format est refusé **au webhook**, pas seulement plus loin : une charge
     * malformée ne doit pas consommer un ouvrier de file pour être rejetée.
     *
     * @dataProvider malformedSessions
     */
    public function test_a_malformed_session_is_refused(string $session): void
    {
        Queue::fake();
        $room = SecureVisioRoomIdGenerator::make();
        $this->recordingInRoom($room);

        $this->signedPost(['room' => $room, 'session' => $session])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_a_payload_with_neither_recording_id_nor_room_is_refused(): void
    {
        Queue::fake();

        $this->signedPost(['session' => self::SESSION])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    // ------------------------------------------- l'authentification prime toujours

    /**
     * Un appelant non signé reçoit **401**, jamais 422 : le détail des champs
     * attendus ne doit pas servir de guide à qui n'est pas authentifié.
     */
    public function test_an_unsigned_room_notification_is_401_not_422(): void
    {
        Queue::fake();

        $this->postJson('/api/webhooks/visio/recording-ready', [
            'room' => 'peu importe',
            'session' => 'malformé',
        ])->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_a_replayed_room_notification_is_refused(): void
    {
        Queue::fake();
        $room = SecureVisioRoomIdGenerator::make();
        $this->recordingInRoom($room);

        $body = ['room' => $room, 'session' => self::SESSION];
        $headers = $this->sign($body, 'nonce-room-replay');

        $this->postJson('/api/webhooks/visio/recording-ready', $body, $headers)->assertStatus(202);
        $this->postJson('/api/webhooks/visio/recording-ready', $body, $headers)->assertStatus(409);
    }

    public function test_a_missing_secret_makes_the_room_path_unavailable(): void
    {
        config(['services.visio.webhook_secret' => '']);

        $this->postJson('/api/webhooks/visio/recording-ready', [
            'room' => SecureVisioRoomIdGenerator::make(),
            'session' => self::SESSION,
        ])->assertStatus(503);
    }

    // --------------------------------------------------- contrepartie du 404 strict

    /**
     * Le refus strict ne doit jamais être **silencieux**.
     *
     * Le bouton « Démarrer l'enregistrement » du LMS ne pilote pas Jibri
     * aujourd'hui : un enseignant peut donc enregistrer depuis l'onglet Jitsi
     * sans qu'aucune ligne n'existe côté LMS. Le fichier est alors bien réel, et
     * le 404 le laisse intact — mais quelqu'un doit pouvoir le savoir pour aller
     * le rattacher. D'où cet événement journalisé, nommément identifiable.
     */
    public function test_an_orphan_recording_is_logged_with_room_and_session(): void
    {
        Log::spy();
        $room = SecureVisioRoomIdGenerator::make();

        // Le salon existe, mais aucun enregistrement n'est actif dessus.
        Seance::factory()->create(['visio_room_id' => $room]);

        $this->signedPost(['room' => $room, 'session' => self::SESSION])->assertStatus(404);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'visio.recording.orphan_no_active_session'
                && ($context['room'] ?? null) === $room
                && ($context['session_id'] ?? null) === self::SESSION)
            ->once();
    }
}
