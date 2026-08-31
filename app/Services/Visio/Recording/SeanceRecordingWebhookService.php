<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Jobs\ImportJibriRecordingMedia;
use App\Jobs\ProcessSeanceRecordingReady;
use App\Models\SeanceRecording;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;

/**
 * #469 — finalisation recording : webhook signé, horodaté, anti-replay.
 * Ne capture pas la vidéo.
 *
 * ## Deux voies, une seule porte
 *
 * - `recording_id` + `url` — voie historique : le fournisseur héberge le média
 *   et connaît notre identifiant (modèle JaaS). Dispatche
 *   {@see ProcessSeanceRecordingReady}.
 * - `room` + `session` — Jibri auto-hébergé : le fournisseur ne connaît que le
 *   salon et le répertoire de sa session. Dispatche
 *   {@see ImportJibriRecordingMedia}, qui va lire le média puis délègue au job
 *   ci-dessus, inchangé.
 *
 * Une seule route pour les deux : elles partagent l'authentification,
 * l'anti-rejeu et l'enveloppe de réponse. Deux routes auraient dupliqué tout
 * cela et doublé la surface d'authentification à auditer.
 */
final class SeanceRecordingWebhookService
{
    /** Fenêtre d'acceptation de l'horodatage, en secondes, si la config est absente ou invalide. */
    private const DEFAULT_MAX_AGE_SECONDS = 300;

    /** Identifiant de session Jibri : UUID minuscule, rien d'autre. */
    private const SESSION_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $logger,
        private readonly RoomRecordingResolver $rooms,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function accept(
        string $rawBody,
        ?string $signature,
        ?string $timestamp,
        ?string $nonce,
        array $payload,
    ): array {
        $secret = config('services.visio.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            return $this->fail(503, 'Webhook enregistrement non configuré.');
        }

        $auth = $this->authenticate($rawBody, $signature, $timestamp, $nonce, $secret);
        if ($auth !== null) {
            return $auth;
        }

        // L'AUTHENTIFICATION PRÉCÈDE LA VALIDATION, et c'est pour ça qu'il n'y a
        // pas de FormRequest sur ce point d'entrée (§1.5) : celle-ci s'exécute
        // avant le contrôleur, donc avant le HMAC. Un appelant non signé
        // recevrait un 422 détaillant les champs attendus — un guide de sondage
        // offert à qui n'est pas authentifié.
        return $this->hasRecordingId($payload)
            ? $this->acceptByRecordingId($payload)
            : $this->acceptByRoom($payload);
    }

    /**
     * Voie HISTORIQUE : le fournisseur héberge le média et connaît notre
     * identifiant (modèle JaaS). **Inchangée** — verrouillée par
     * {@see \Tests\Feature\LMS\Visio\VisioRecordingWebhookTest}.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status:int, payload: array<string, mixed>}
     */
    private function acceptByRecordingId(array $payload): array
    {
        // Renarrow ici plutôt que de se fier au garde de `hasRecordingId()` :
        // une garantie posée dans une autre méthode n'est pas vérifiable par
        // l'analyse statique, et un jour quelqu'un appellera celle-ci
        // directement.
        $rawId = $payload['recording_id'] ?? null;
        $recordingId = is_numeric($rawId) ? (int) $rawId : 0;
        $url = isset($payload['url']) && is_string($payload['url']) ? $payload['url'] : '';
        if ($recordingId < 1 || $url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->fail(422, 'Payload enregistrement invalide.');
        }

        $recording = SeanceRecording::withoutGlobalScope('institution')->find($recordingId);
        if ($recording === null) {
            return $this->fail(404, 'Enregistrement introuvable.');
        }

        $provider = isset($payload['provider']) && is_string($payload['provider'])
            ? $payload['provider']
            : 'external';

        ProcessSeanceRecordingReady::dispatch($recording->id, $url, $this->title($payload), $provider)
            ->onQueue('low');

        $this->logger->info('visio.recording.webhook.accepted', [
            'recording_id' => $recording->id,
        ]);

        return $this->accepted();
    }

    /**
     * Voie JIBRI AUTO-HÉBERGÉ : le fournisseur ne connaît que le salon et le
     * répertoire de sa session. Le média ne transite pas ici — le LMS ira le
     * lire lui-même ({@see \App\Jobs\ImportJibriRecordingMedia}).
     *
     * Le format de session est vérifié **ici** et pas seulement à l'import :
     * une charge malformée ne doit pas consommer un ouvrier de file pour être
     * rejetée.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status:int, payload: array<string, mixed>}
     */
    private function acceptByRoom(array $payload): array
    {
        $room = isset($payload['room']) && is_string($payload['room']) ? trim($payload['room']) : '';
        $session = isset($payload['session']) && is_string($payload['session']) ? $payload['session'] : '';

        if ($room === '' || preg_match(self::SESSION_PATTERN, $session) !== 1) {
            return $this->fail(422, 'Payload enregistrement invalide.');
        }

        $recording = $this->rooms->resolve($room);

        if ($recording === null) {
            // Le 404 est STRICT : on n'invente pas d'enregistrement, sans quoi
            // on contournerait le contrôle d'accès de `start()` et on perdrait
            // l'acteur de la piste d'audit.
            //
            // Mais il ne doit pas être SILENCIEUX : le fichier existe bel et
            // bien côté Jibri, et le script de finalisation ne le supprime
            // jamais. Cet événement est ce qui permet de le retrouver et de le
            // rattacher à la main.
            $this->logger->warning('visio.recording.orphan_no_active_session', [
                'room' => $room,
                'session_id' => $session,
            ]);

            return $this->fail(404, 'Enregistrement introuvable.');
        }

        ImportJibriRecordingMedia::dispatch(
            $recording->id,
            $session,
            $this->title($payload),
        )->onQueue('low');

        $this->logger->info('visio.recording.webhook.accepted', [
            'recording_id' => $recording->id,
            'session_id' => $session,
        ]);

        return $this->accepted();
    }

    /** @param  array<string, mixed>  $payload */
    private function hasRecordingId(array $payload): bool
    {
        return isset($payload['recording_id']) && is_numeric($payload['recording_id']);
    }

    /** @param  array<string, mixed>  $payload */
    private function title(array $payload): ?string
    {
        return isset($payload['title']) && is_string($payload['title']) ? $payload['title'] : null;
    }

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    private function accepted(): array
    {
        return [
            'status' => 202,
            'payload' => [
                'success' => true,
                'message' => 'Enregistrement accepté pour traitement.',
            ],
        ];
    }

    /**
     * @return array{status:int, payload: array<string, mixed>}|null
     */
    private function authenticate(
        string $rawBody,
        ?string $signature,
        ?string $timestamp,
        ?string $nonce,
        string $secret,
    ): ?array {
        if ($signature === null || $timestamp === null || $nonce === null
            || $signature === '' || $timestamp === '' || $nonce === '') {
            return $this->fail(401, 'Signature webhook manquante.');
        }

        if (! ctype_digit($timestamp)) {
            return $this->fail(401, 'Horodatage webhook invalide.');
        }

        // config() retourne mixed : on ne caste qu'une valeur réellement numérique,
        // sinon on retombe sur la fenêtre par défaut plutôt que sur un maxAge à 0
        // (qui rejetterait tout webhook, y compris légitime).
        $configuredMaxAge = config('services.visio.webhook_max_age', self::DEFAULT_MAX_AGE_SECONDS);
        $maxAge = is_numeric($configuredMaxAge)
            ? (int) $configuredMaxAge
            : self::DEFAULT_MAX_AGE_SECONDS;

        if (abs(time() - (int) $timestamp) > $maxAge) {
            return $this->fail(401, 'Horodatage webhook expiré.');
        }

        $expected = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$rawBody, $secret);
        $provided = str_starts_with($signature, 'sha256=')
            ? substr($signature, 7)
            : $signature;
        if (! hash_equals($expected, $provided)) {
            return $this->fail(401, 'Signature webhook invalide.');
        }

        $nonceKey = 'visio_webhook_nonce_'.$nonce;
        if (! $this->cache->add($nonceKey, 1, $maxAge + 60)) {
            return $this->fail(409, 'Webhook déjà traité.');
        }

        return null;
    }

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    private function fail(int $status, string $message): array
    {
        return [
            'status' => $status,
            'payload' => [
                'success' => false,
                'message' => $message,
            ],
        ];
    }
}
