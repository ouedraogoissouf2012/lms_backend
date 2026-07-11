<?php

declare(strict_types=1);

namespace App\Services\Visio;

final class SecureVisioRoomIdGenerator
{
    private const PREFIX = 'lms_';

    public function generate(): string
    {
        return self::make();
    }

    public static function make(): string
    {
        return self::PREFIX.bin2hex(random_bytes(20));
    }
}
