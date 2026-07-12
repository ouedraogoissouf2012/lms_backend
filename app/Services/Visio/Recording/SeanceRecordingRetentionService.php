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
    ) {}

    public function eligible(SeanceRecording $recording, CarbonInterface $cutoff): bool
    {
        if ($recording->status->isActive()) {
            return false;
        }

        $anchor = $recording->processed_at ?? $recording->stopped_at ?? $recording->created_at;

        return $anchor !== null && $anchor->lt($cutoff);
    }

    public function purge(SeanceRecording $recording, CarbonInterface $cutoff): ?bool
    {
        return $this->database->transaction(function () use ($recording, $cutoff): ?bool {
            $locked = SeanceRecording::query()
                ->with(['seance', 'chapter'])
                ->whereKey($recording->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $this->eligible($locked, $cutoff)) {
                return null;
            }

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
