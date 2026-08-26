<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Concerns;

use App\Http\Requests\Concerns\AuthorizesTenantScopedResource;
use App\Models\User;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

#[CoversTrait(AuthorizesTenantScopedResource::class)]
final class AuthorizesTenantScopedResourceTest extends TestCase
{
    private function subject(): object
    {
        return new class () {
            use AuthorizesTenantScopedResource {
                passesTenantGuard as public;
            }
        };
    }

    public function test_supradmin_is_allowed_across_tenants(): void
    {
        $user = (new User())->forceFill(['role' => 'supradmin', 'institution_id' => null]);
        self::assertTrue($this->subject()->passesTenantGuard($user, 99));
    }

    public function test_super_admin_is_not_treated_as_platform_supradmin(): void
    {
        $user = (new User())->forceFill(['role' => 'superAdmin', 'institution_id' => 1]);
        self::assertFalse($this->subject()->passesTenantGuard($user, 2));
    }

    public function test_same_tenant_continues_to_domain_rule(): void
    {
        $user = (new User())->forceFill(['role' => 'enseignant', 'institution_id' => 1]);
        self::assertNull($this->subject()->passesTenantGuard($user, 1));
    }
}
