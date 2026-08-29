<?php

declare(strict_types=1);

namespace App\Enums;

enum SeanceRecordingStatus: string
{
    case Idle = 'idle';
    case Recording = 'recording';
    case Uploading = 'uploading';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases(),
        );
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [
            self::Recording->value,
            self::Uploading->value,
            self::Processing->value,
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->value, self::activeValues(), true);
    }
}
