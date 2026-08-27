<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * #549 — présence des index klassci_matiere_id / klassci_tenant_url.
 */
final class MissingIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_seances_has_tenant_leading_matiere_index(): void
    {
        $names = collect(Schema::getIndexes('seances'))->pluck('name');

        self::assertTrue($names->contains('seances_inst_matiere_idx'));
    }

    public function test_users_has_tenant_leading_klassci_url_index(): void
    {
        $names = collect(Schema::getIndexes('users'))->pluck('name');

        self::assertTrue($names->contains('users_inst_tenant_url_idx'));
    }
}
