<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth;

use App\Models\Institution;
use App\Models\User;
use App\Services\Auth\LocalLmsAuthenticator;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Issue #120 — Tests unit pour LocalLmsAuthenticator.
 *
 * Couvre :
 * - Match par email + password OK
 * - Match par name + password OK
 * - User introuvable → null
 * - Password incorrect → null
 * - User sans password (KLASSCI user) → null
 * - Recherche cross-institution (supradmin)
 *
 * @see app/Services/Auth/LocalLmsAuthenticator.php
 * @see .claude/specs/auth-controller-refactor/requirements.md REQ-4
 */
#[CoversClass(LocalLmsAuthenticator::class)]
final class LocalLmsAuthenticatorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_user_when_email_matches_and_password_correct(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create([
            'email'    => 'alpha@test.com',
            'password' => 'hashed_password_alpha',
        ]);

        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldReceive('check')
            ->with('plain_pwd', Mockery::type('string'))
            ->andReturn(true);

        $logger = Mockery::spy(LoggerInterface::class);
        $auth = new LocalLmsAuthenticator($hasher, $logger);

        $result = $auth->attemptLocalAuth('alpha@test.com', 'plain_pwd');

        self::assertNotNull($result);
        self::assertSame($user->id, $result->id);
    }

    public function test_returns_user_when_name_matches_and_password_correct(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create([
            'name'     => 'SomeUsername',
            'email'    => 'real@example.com',
            'password' => 'hashed_password_beta',
        ]);

        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldReceive('check')
            ->with('plain_pwd', Mockery::type('string'))
            ->andReturn(true);

        $logger = Mockery::spy(LoggerInterface::class);
        $auth = new LocalLmsAuthenticator($hasher, $logger);

        $result = $auth->attemptLocalAuth('SomeUsername', 'plain_pwd');

        self::assertNotNull($result);
        self::assertSame($user->id, $result->id);
    }

    public function test_returns_null_when_user_not_found(): void
    {
        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldNotReceive('check');

        $logger = Mockery::spy(LoggerInterface::class);
        $auth = new LocalLmsAuthenticator($hasher, $logger);

        $result = $auth->attemptLocalAuth('unknown@nowhere.com', 'any_pwd');

        self::assertNull($result);
    }

    public function test_returns_null_when_password_incorrect(): void
    {
        $institution = Institution::factory()->create();
        User::factory()->for($institution)->create([
            'email'    => 'gamma@test.com',
            'password' => 'hashed_password_gamma',
        ]);

        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldReceive('check')
            ->with('wrong_pwd', Mockery::type('string'))
            ->andReturn(false);

        $logger = Mockery::spy(LoggerInterface::class);
        $auth = new LocalLmsAuthenticator($hasher, $logger);

        $result = $auth->attemptLocalAuth('gamma@test.com', 'wrong_pwd');

        self::assertNull($result);
    }

    public function test_returns_null_when_password_is_null(): void
    {
        // User KLASSCI sans password local — ne doit pas pouvoir login en local
        $institution = Institution::factory()->create();
        User::factory()->for($institution)->create([
            'email'    => 'klassci-only@test.com',
            'password' => null,
        ]);

        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldNotReceive('check');

        $logger = Mockery::spy(LoggerInterface::class);
        $auth = new LocalLmsAuthenticator($hasher, $logger);

        $result = $auth->attemptLocalAuth('klassci-only@test.com', 'any_pwd');

        self::assertNull($result);
    }

    public function test_finds_user_cross_institution_via_without_global_scope(): void
    {
        // Supradmin pattern : user sans institution_id, doit être trouvable
        $supradmin = User::factory()->create([
            'email'          => 'super@admin.com',
            'password'       => 'hashed_super',
            'institution_id' => null,
            'role'           => 'supradmin',
        ]);

        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldReceive('check')
            ->with('admin_pwd', Mockery::type('string'))
            ->andReturn(true);

        $logger = Mockery::spy(LoggerInterface::class);
        $auth = new LocalLmsAuthenticator($hasher, $logger);

        $result = $auth->attemptLocalAuth('super@admin.com', 'admin_pwd');

        self::assertNotNull($result);
        self::assertSame($supradmin->id, $result->id);
        self::assertNull($result->institution_id);
    }
}
