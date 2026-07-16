<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\EnsureKlassciSync;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

abstract class EnsureKlassciSyncTestCase extends TestCase
{
    use RefreshDatabase;

    protected Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function staleUser(string $role, string $email = 'lms@example.com'): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => $role,
            'klassci_role' => $role,
            'email' => $email,
            'name' => 'Original Name',
            'klassci_data' => json_encode(['original' => true]),
            'last_klassci_sync' => now()->subHours(25),
        ]);
    }

    /**
     * @return \ArrayObject<int, array{0: string, 1: array<string, mixed>}>
     */
    protected function captureLogWarnings(): \ArrayObject
    {
        /** @var \ArrayObject<int, array{0: string, 1: array<string, mixed>}> $captured */
        $captured = new \ArrayObject;
        $spy = Mockery::mock();
        $spy->shouldReceive('warning')
            ->andReturnUsing(function (string $event, array $context = []) use ($captured): void {
                $captured->append([$event, $context]);
            });
        $spy->shouldReceive('info', 'debug', 'notice', 'error', 'critical', 'alert', 'emergency')
            ->andReturnNull();

        Log::swap($spy);

        return $captured;
    }

    /**
     * @param  \ArrayObject<int, array{0: string, 1: array<string, mixed>}>  $captured
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    protected function findWarningByEvent(\ArrayObject $captured, string $event): ?array
    {
        foreach ($captured as $call) {
            if ($call[0] === $event) {
                return $call;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $klassciUser */
    protected function runMiddlewareWith(User $user, array $klassciUser): Response
    {
        $klassciService = Mockery::mock(KlassciProxyService::class);
        $klassciService->shouldReceive('requestWithUserToken')
            ->with($user->klassci_token, 'auth/me', 'GET')
            ->andReturn(['data' => ['user' => $klassciUser]]);

        $request = Request::create('/api/dummy', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = new EnsureKlassciSync($klassciService);

        return $middleware->handle($request, fn ($nextRequest) => new Response('ok', 200));
    }
}
