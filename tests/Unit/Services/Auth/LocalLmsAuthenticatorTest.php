<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth;

use App\Models\Institution;
use App\Models\User;
use App\Services\Auth\EmpreinteLeurre;
use App\Services\Auth\LocalLmsAuthenticator;
use App\Support\Logging\CaughtExceptionLog;
use App\Support\Logging\ExceptionDescription;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
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
 * - User introuvable → null, après UNE vérification contre le leurre (#687)
 * - Password incorrect → null
 * - User sans password (KLASSCI user) → null, après UNE vérification contre le leurre
 * - Recherche cross-institution (supradmin)
 *
 * @see app/Services/Auth/LocalLmsAuthenticator.php
 * @see .claude/specs/auth-controller-refactor/requirements.md REQ-4
 */
#[CoversClass(LocalLmsAuthenticator::class)]
final class LocalLmsAuthenticatorTest extends TestCase
{
    use RefreshDatabase;

    /** L'empreinte que rend le hacheur feint pour le leurre (#687). */
    private const LEURRE = 'empreinte-du-leurre';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * L'authentificateur, avec un leurre réel bâti sur le MÊME hacheur : une
     * vérification contre le leurre se compte donc comme les autres.
     */
    private function authentificateur(Hasher $hasher, LoggerInterface $logger): LocalLmsAuthenticator
    {
        $leurre = new EmpreinteLeurre(
            $hasher,
            new Repository(new ArrayStore),
            new CaughtExceptionLog(Mockery::spy(LoggerInterface::class), new ExceptionDescription),
        );

        return new LocalLmsAuthenticator($hasher, $logger, $leurre);
    }

    public function test_returns_user_when_email_matches_and_password_correct(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create([
            'email' => 'alpha@test.com',
            'password' => 'hashed_password_alpha',
        ]);

        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldReceive('check')
            ->with('plain_pwd', Mockery::type('string'))
            ->andReturn(true);

        $logger = Mockery::spy(LoggerInterface::class);
        $auth = $this->authentificateur($hasher, $logger);

        $result = $auth->attemptLocalAuth('alpha@test.com', 'plain_pwd');

        self::assertNotNull($result);
        self::assertSame($user->id, $result->id);
    }

    public function test_returns_user_when_name_matches_and_password_correct(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create([
            'name' => 'SomeUsername',
            'email' => 'real@example.com',
            'password' => 'hashed_password_beta',
        ]);

        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldReceive('check')
            ->with('plain_pwd', Mockery::type('string'))
            ->andReturn(true);

        $logger = Mockery::spy(LoggerInterface::class);
        $auth = $this->authentificateur($hasher, $logger);

        $result = $auth->attemptLocalAuth('SomeUsername', 'plain_pwd');

        self::assertNotNull($result);
        self::assertSame($user->id, $result->id);
    }

    public function test_returns_null_when_user_not_found(): void
    {
        // #687 : un identifiant inconnu coûte UNE vérification, contre le leurre,
        // comme un mot de passe faux. Zéro vérification se reconnaissait au
        // chronomètre (~23 ms contre ~519 ms, mesuré le 2026-09-26).
        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldReceive('make')->once()->andReturn(self::LEURRE);
        $hasher->shouldReceive('check')->once()->with('any_pwd', self::LEURRE)->andReturn(false);

        $logger = Mockery::spy(LoggerInterface::class);
        $auth = $this->authentificateur($hasher, $logger);

        $result = $auth->attemptLocalAuth('unknown@nowhere.com', 'any_pwd');

        self::assertNull($result);
    }

    public function test_returns_null_when_password_incorrect(): void
    {
        $institution = Institution::factory()->create();
        User::factory()->for($institution)->create([
            'email' => 'gamma@test.com',
            'password' => 'hashed_password_gamma',
        ]);

        $hasher = Mockery::mock(Hasher::class);
        // Une vraie vérification a eu lieu : le leurre n'est pas demandé (#687).
        $hasher->shouldReceive('check')
            ->once()
            ->with('wrong_pwd', Mockery::type('string'))
            ->andReturn(false);
        $hasher->shouldNotReceive('make');

        $logger = Mockery::spy(LoggerInterface::class);
        $auth = $this->authentificateur($hasher, $logger);

        $result = $auth->attemptLocalAuth('gamma@test.com', 'wrong_pwd');

        self::assertNull($result);
    }

    public function test_returns_null_when_password_is_null(): void
    {
        // User KLASSCI sans password local — ne doit pas pouvoir login en local
        $institution = Institution::factory()->create();
        User::factory()->for($institution)->create([
            'email' => 'klassci-only@test.com',
            'password' => null,
        ]);

        // #687 : aucune empreinte à vérifier, donc une vérification contre le
        // leurre, pour que ce chemin coûte comme les autres.
        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldReceive('make')->once()->andReturn(self::LEURRE);
        $hasher->shouldReceive('check')->once()->with('any_pwd', self::LEURRE)->andReturn(false);

        $logger = Mockery::spy(LoggerInterface::class);
        $auth = $this->authentificateur($hasher, $logger);

        $result = $auth->attemptLocalAuth('klassci-only@test.com', 'any_pwd');

        self::assertNull($result);
    }

    public function test_finds_user_cross_institution_via_without_global_scope(): void
    {
        // Supradmin pattern : user sans institution_id, doit être trouvable
        $supradmin = User::factory()->create([
            'email' => 'super@admin.com',
            'password' => 'hashed_super',
            'institution_id' => null,
            'role' => 'supradmin',
        ]);

        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldReceive('check')
            ->with('admin_pwd', Mockery::type('string'))
            ->andReturn(true);

        $logger = Mockery::spy(LoggerInterface::class);
        $auth = $this->authentificateur($hasher, $logger);

        $result = $auth->attemptLocalAuth('super@admin.com', 'admin_pwd');

        self::assertNotNull($result);
        self::assertSame($supradmin->id, $result->id);
        self::assertNull($result->institution_id);
    }
}
