<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Models\Chapter;
use App\Models\SeanceRecording;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

final class SeanceRecordingRetentionService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly LoggerInterface $logger,
        private readonly RecordingMediaStorage $media,
    ) {}

    public function eligible(SeanceRecording $recording, CarbonInterface $cutoff): bool
    {
        if ($recording->status->isActive()) {
            return false;
        }

        $anchor = $recording->processed_at ?? $recording->stopped_at ?? $recording->created_at;

        return $anchor !== null && $anchor->lt($cutoff);
    }

    /**
     * Purge un enregistrement expiré : son **média**, puis ses lignes.
     *
     * ## Pourquoi le fichier est effacé DANS la transaction, avant les lignes
     *
     * Une suppression de fichier n'est pas transactionnelle : les deux ordres
     * possibles laissent un état incohérent si quelque chose casse au milieu.
     * Le choix se fait donc sur la nature du résidu, pas sur l'élégance.
     *
     * - **Effacer après le commit** : si l'effacement échoue, la ligne a disparu
     *   et plus rien ne référence le fichier — donc plus aucune purge ultérieure
     *   ne saura le retrouver. La vidéo reste servie, définitivement.
     * - **Effacer avant, dans la transaction** (retenu) : si la transaction est
     *   annulée, la ligne subsiste en pointant sur un média absent. Le chapitre
     *   affiche une vidéo cassée — visible, journalisé, réparable.
     *
     * Ce service existe pour faire disparaître des données à échéance. Quand les
     * deux issues sont mauvaises, la bonne direction est « effacé quoi qu'il
     * arrive », jamais « métadonnées perdues, vidéo encore en ligne » : cette
     * seconde forme *documente* une suppression qui n'a pas eu lieu.
     */
    public function purge(SeanceRecording $recording, CarbonInterface $cutoff): ?bool
    {
        return $this->database->transaction(function () use ($recording, $cutoff): ?bool {
            $locked = SeanceRecording::withoutGlobalScope('institution')
                ->with(['seance', 'chapter'])
                ->whereKey($recording->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $this->eligible($locked, $cutoff)) {
                return null;
            }

            // Avant toute suppression de ligne : tant que l'enregistrement
            // existe encore, on sait quel média lui appartient.
            $this->media->purge($locked->id);

            $chapter = $locked->chapter;
            $chapterPurged = $chapter !== null && $this->ownsGeneratedChapter($locked, $chapter);
            if ($chapterPurged) {
                $chapter->forceDelete();
            }

            $locked->delete();

            return $chapterPurged;
        });
    }

    public function logFailure(SeanceRecording $recording, Throwable $exception): void
    {
        $this->logger->error('Visio recording retention purge failed', [
            'recording_id' => $recording->getKey(),
            'seance_id' => $recording->seance_id,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    private function ownsGeneratedChapter(SeanceRecording $recording, Chapter $chapter): bool
    {
        $notes = $chapter->notes_enseignant;
        $seance = $recording->seance;

        return $seance !== null
            && $chapter->institution_id === $seance->institution_id
            && is_array($notes)
            && ($notes['source'] ?? null) === 'visio_recording'
            && (int) ($notes['seance_id'] ?? 0) === (int) $recording->seance_id;
    }
}
