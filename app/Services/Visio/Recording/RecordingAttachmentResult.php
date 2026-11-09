<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Enums\RecordingFailureReason;
use App\Models\Chapter;
use App\Models\Lesson;

final class RecordingAttachmentResult
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public readonly bool $success,
        public readonly string $reason,
        public readonly ?Lesson $lesson,
        public readonly ?Chapter $chapter,
        public readonly array $context = [],
        public readonly ?RecordingFailureReason $failureReason = null,
    ) {}

    public static function attached(Lesson $lesson, Chapter $chapter): self
    {
        return new self(true, 'attached', $lesson, $chapter);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function failed(RecordingFailureReason $reason, array $context = []): self
    {
        return new self(false, $reason->value, null, null, $context, $reason);
    }
}
