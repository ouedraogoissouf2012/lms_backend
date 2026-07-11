<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Visio;

use App\Services\Visio\SecureVisioRoomIdGenerator;
use PHPUnit\Framework\TestCase;

final class SecureVisioRoomIdGeneratorTest extends TestCase
{
    public function test_room_ids_are_random_and_do_not_embed_seance_ids_or_timestamps(): void
    {
        $generator = new SecureVisioRoomIdGenerator;

        $first = $generator->generate();
        $second = $generator->generate();

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^lms_[a-z0-9]{40}$/', $first);
        $this->assertMatchesRegularExpression('/^lms_[a-z0-9]{40}$/', $second);
        $this->assertDoesNotMatchRegularExpression('/^lms_seance_\d+_\d+$/', $first);
        $this->assertDoesNotMatchRegularExpression('/^lms_seance_\d+_\d+$/', $second);
    }
}
