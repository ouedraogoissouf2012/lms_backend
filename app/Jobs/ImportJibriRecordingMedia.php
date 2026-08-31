<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SeanceRecordingStatus;
use App\Models\SeanceRecording;
use App\Services\Visio\Recording\RecordingMediaSource;
use App\Services\Visio\Recording\RecordingMediaStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

/**
 * #469 — le LMS s'approprie le média produit par Jibri, puis délègue.
 *
 * ## Pourquoi un job séparé plutôt qu'une branche dans l'existant
 *
 * {@see ProcessSeanceRecordingReady} rattache une URL à une leçon : c'est la
 * partie subtile du domaine (leçon unique ? ambiguë ? absente ?). Elle est
 * écrite, testée, et **n'est pas touchée ici**.
 *
 * Ce job répond à une autre question : « d'où vient le fichier, et à qui
 * appartient-il ». Les mélanger aurait fait grossir une classe déjà chargée et
 * couplé la résolution pédagogique à un détail d'hébergement.
 *
 * ## Le fichier source n'est jamais supprimé
 *
 * Le fournisseur reste maître de ses fichiers. Un import raté doit pouvoir être
 * rejoué : supprimer la source ferait de la première tentative la seule.
 */
final class ImportJibriRecordingMedia implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(
        private readonly int $recordingId,
        private readonly string $sessionId,
        private readonly ?string $title = null,
    ) {}

    public function handle(
        RecordingMediaSource $source,
        RecordingMediaStorage $storage,
        LoggerInterface $logger,
    ): void {
        $recording = SeanceRecording::withoutGlobalScope('institution')->find($this->recordingId);

        // Deja publie : une notification tardive ou rejouee ne doit pas
        // reecrire un cours en ligne. Meme garde que ProcessSeanceRecordingReady.
        if ($recording === null || $recording->status === SeanceRecordingStatus::Ready) {
            return;
        }

        $absolutePath = $source->locate($this->sessionId);

        if ($absolutePath === null) {
            $this->fail($recording, 'media_not_found', $logger);

            return;
        }

        $relativePath = $storage->store($absolutePath, $recording->id);

        if ($relativePath === null) {
            $this->fail($recording, 'media_copy_failed', $logger);

            return;
        }

        $recording->update([
            'provider' => 'jibri',
            'provider_recording_id' => $this->sessionId,
            'file_size_bytes' => $this->sizeOf($absolutePath),
            'error_message' => null,
        ]);

        ProcessSeanceRecordingReady::dispatch(
            $recording->id,
            $storage->url($relativePath),
            $this->title,
            'jibri',
        )->onQueue('low');
    }

    /**
     * Motif d'echec = CLE STABLE, jamais un message d'exception : `error_message`
     * est serialise dans la reponse API (§1.2). Le detail technique va au
     * journal, ou il est utile sans etre expose.
     */
    private function fail(SeanceRecording $recording, string $reason, LoggerInterface $logger): void
    {
        $recording->update([
            'status' => SeanceRecordingStatus::Failed,
            'error_message' => $reason,
        ]);

        $logger->warning('visio.recording.import.failed', [
            'recording_id' => $recording->getKey(),
            'session_id' => $this->sessionId,
            'reason' => $reason,
        ]);
    }

    /**
     * Mesuree sur le fichier, jamais annoncee par l'appelant : une taille
     * declaree par le reseau serait une donnee non verifiee stockee en base.
     */
    private function sizeOf(string $absolutePath): ?int
    {
        $size = @filesize($absolutePath);

        return is_int($size) ? $size : null;
    }
}
