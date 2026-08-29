<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

final class RecordingRetentionResult
{
    public int $eligible = 0;

    public int $purged = 0;

    public int $chaptersPurged = 0;

    public int $providerFilesIgnored = 0;

    public int $ignored = 0;

    public int $failed = 0;
}
