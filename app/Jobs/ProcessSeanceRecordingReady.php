<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SeanceRecordingStatus;
use App\Models\SeanceRecording;
use App\Services\Visio\Recording\SeanceRecordingAttachmentResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Throwable;

final class ProcessSeanceRecordingReady implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(
        private readonly int $recordingId,
        private readonly string $recordingUrl,
        private readonly ?string $title = null,
        private readonly string $provider = 'external',
    ) {}

    public function handle(
        SeanceRecordingAttachmentResolver $resolver,
        LoggerInterface $logger,
    ): void {
        $recording = SeanceRecording::query()->with('seance')->find($this->recordingId);
        if ($recording === null || $recording->status === SeanceRecordingStatus::Ready) {
            return;
        }

        $seance = $recording->seance;
        if ($seance === null) {
            $this->markFailed($recording, 'seance_not_found');

            return;
        }

        $recording->update([
            'provider' => $this->provider,
            'recording_url' => $this->recordingUrl,
            'title' => $this->title,
            'status' => SeanceRecordingStatus::Processing,
            'error_message' => null,
        ]);

        try {
            $result = $resolver->attachReadyRecording(
                $seance,
                $this->recordingUrl,
                $this->title,
                $this->provider,
            );
        } catch (Throwable $exception) {
            $this->markFailed($recording, $exception->getMessage());
            throw $exception;
        }

        if (! $result->success) {
            $this->markFailed($recording, $result->reason);
            $logger->warning('[ProcessSeanceRecordingReady] Recording attachment failed', [
                'recording_id' => $recording->id,
                'reason' => $result->reason,
            ]);

            return;
        }

        $recording->update([
            'lesson_id' => $result->lesson?->id,
            'chapter_id' => $result->chapter?->id,
            'status' => SeanceRecordingStatus::Ready,
            'processed_at' => now(),
            'error_message' => null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $recording = SeanceRecording::query()->find($this->recordingId);
        if ($recording !== null) {
            $this->markFailed($recording, $exception->getMessage());
        }
    }

    private function markFailed(SeanceRecording $recording, string $message): void
    {
        $recording->update([
            'status' => SeanceRecordingStatus::Failed,
            'error_message' => $message,
            'processed_at' => now(),
        ]);
    }
}
