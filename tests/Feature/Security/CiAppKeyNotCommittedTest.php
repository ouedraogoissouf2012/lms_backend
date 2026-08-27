<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * #549 — APP_KEY ne doit plus être versionné dans le workflow CI.
 */
final class CiAppKeyNotCommittedTest extends TestCase
{
    public function test_security_workflow_generates_app_key_instead_of_committing_it(): void
    {
        $yml = (string) file_get_contents(base_path('.github/workflows/security.yml'));

        self::assertStringNotContainsString(
            'Cg9vQ0xtc1Rlc3RLZXkxMjM0NTY3ODkwYWJjZGVmZ2g',
            $yml,
        );
        self::assertStringContainsString('openssl rand -base64 32', $yml);
        self::assertSame(2, substr_count($yml, 'Generate ephemeral APP_KEY'));
    }
}
