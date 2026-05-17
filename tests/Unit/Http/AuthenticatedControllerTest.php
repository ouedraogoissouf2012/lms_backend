<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\AuthenticatedController;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Contract tests for {@see AuthenticatedController}.
 *
 * The base class exposes a single protected helper, `authenticatedUser()`,
 * that converts the runtime auth guarantee into a typed `User` for the
 * static analyser. Three scenarios pin down the contract :
 *
 *   1. A request with a `User` returns that User unmodified.
 *   2. A request with `null` user raises {@see AuthenticationException}
 *      (which Laravel renders as `401 Unauthenticated`).
 *   3. A request with an `Authenticatable` that is NOT a `User` (e.g.
 *      a different model on a different guard) also raises 401-ready
 *      `AuthenticationException` — fail-secure rather than silently
 *      returning the wrong type.
 *
 * A throwaway concrete subclass is declared to expose the protected
 * method, since the base class is abstract.
 */
#[CoversClass(AuthenticatedController::class)]
final class AuthenticatedControllerTest extends TestCase
{
    public function test_returns_user_when_request_has_authenticated_user(): void
    {
        $expected = (new User())->forceFill(['id' => 42, 'name' => 'Alice']);

        $request = Request::create('/test');
        $request->setUserResolver(static fn () => $expected);

        $actual = $this->makeSubject()->callAuthenticatedUser($request);

        $this->assertSame($expected, $actual);
    }

    public function test_throws_when_request_has_no_authenticated_user(): void
    {
        $request = Request::create('/test');
        $request->setUserResolver(static fn () => null);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Authenticated User required');

        $this->makeSubject()->callAuthenticatedUser($request);
    }

    public function test_throws_when_request_user_is_not_an_app_user_instance(): void
    {
        // A non-User Authenticatable (e.g. another guard's model). The
        // helper must NOT trust the duck-typing — only `App\Models\User`
        // is allowed.
        $foreign = new class () implements Authenticatable {
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): int { return 1; }
            public function getAuthPassword(): string { return ''; }
            public function getAuthPasswordName(): string { return 'password'; }
            public function getRememberToken(): string { return ''; }
            public function setRememberToken($value): void {}
            public function getRememberTokenName(): string { return 'remember_token'; }
        };

        $request = Request::create('/test');
        $request->setUserResolver(static fn () => $foreign);

        $this->expectException(AuthenticationException::class);

        $this->makeSubject()->callAuthenticatedUser($request);
    }

    /**
     * Concrete subclass exposing the protected helper for assertions.
     */
    private function makeSubject(): object
    {
        return new class () extends AuthenticatedController {
            public function callAuthenticatedUser(Request $request): User
            {
                return $this->authenticatedUser($request);
            }
        };
    }
}
