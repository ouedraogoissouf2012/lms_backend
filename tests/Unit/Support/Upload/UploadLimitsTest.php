<?php

namespace Tests\Unit\Support\Upload;

use App\Support\Upload\UploadLimits;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou direct contre la régression #576 : la limite d'upload doit rester
 * exprimée en KILO-OCTETS (unité de la règle `max` d'un fichier Laravel), pas
 * en octets. Si un jour quelqu'un remet `30 * 1024 * 1024`, ces tests cassent.
 */
class UploadLimitsTest extends TestCase
{
    public function test_max_kilobytes_is_30_mb_expressed_in_kilobytes(): void
    {
        // 30 Mo = 30 * 1024 = 30 720 Ko. Surtout PAS 31 457 280 (= 30 Mo en octets).
        $this->assertSame(30720, UploadLimits::MAX_KILOBYTES);
    }

    public function test_max_rule_targets_the_kilobyte_value(): void
    {
        $this->assertSame('max:30720', UploadLimits::maxRule());
    }

    public function test_human_readable_is_derived_from_the_constant(): void
    {
        $this->assertSame('30 MB', UploadLimits::humanReadable());
    }
}
