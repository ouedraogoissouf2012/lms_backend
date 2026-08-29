<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * #549 — le compte cPanel ne doit plus apparaître dans le dépôt.
 */
final class CpanelAccountNotCommittedTest extends TestCase
{
    public function test_leaked_cpanel_account_is_absent_from_tracked_docs(): void
    {
        self::assertFileDoesNotExist(base_path('.cpanel.yml'));

        $account = 'c2569'.'688c';
        $paths = [
            'GUIDE_DEPLOIEMENT_PRODUCTION.md',
            'docs/DEPLOYMENT_OPS.md',
            'docs/DEPLOIEMENT_CPANEL.md',
            'docs/CPANEL_SCALABILITY_PLAN.md',
            'docs/REVERSE_ENGINEERING.md',
            'docs/RUNBOOK_PURGE_HISTORIQUE.md',
            'docs/VISIO_RECORDING_RETENTION.md',
        ];
        foreach ($paths as $relative) {
            $contents = (string) file_get_contents(base_path($relative));
            self::assertStringNotContainsString($account, $contents, $relative);
        }
    }
}
