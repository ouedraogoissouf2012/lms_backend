<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\SeanceQueryService;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Contract tests for `SeanceQueryService` (PR E of LMSDataController split).
 *
 * The service has a complex business logic with multiple branches (teacher /
 * student / coordinateur) and KLASSCI fallbacks. These tests focus on the
 * **contract surface**: error paths (missing token, no seance found) and the
 * basic shape of the returned array when successful.
 *
 * Full integration tests (with Seance / Visio DB persistence) come in PR F
 * when `LMSSeancesController` is extracted and migrated to use this service.
 *
 * @see app/Services/SeanceQueryService.php
 * @see .claude/specs/lms-data-controller-split/design.md §2.2
 */
#[CoversClass(SeanceQueryService::class)]
final class SeanceQueryServiceTest extends TestCase
{
    public function test_throws_when_user_has_no_klassci_token(): void
    {
        $klassciMock = Mockery::mock(KlassciProxyService::class);
        $service = new SeanceQueryService($klassciMock);

        $user = (new User())->forceFill([
            'id' => 1,
            'role' => 'enseignant',
            'klassci_token' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token KLASSCI non trouvé');

        $service->getSeanceDetailsArray(42, $user);
    }

    public function test_returns_null_when_seance_not_found_anywhere(): void
    {
        $klassciMock = Mockery::mock(KlassciProxyService::class);
        $klassciMock->shouldReceive('requestWithUserToken')
            ->andReturn(['data' => ['matieres' => []]]);

        $service = new SeanceQueryService($klassciMock);

        $user = (new User())->forceFill([
            'id' => 1,
            'role' => 'enseignant',
            'klassci_token' => 'fake-token',
            'klassci_id' => 99,
            'name' => 'Test Teacher',
            'email' => 'teacher@example.com',
        ]);

        $result = $service->getSeanceDetailsArray(42, $user);

        self::assertNull($result);
    }

    public function test_getProgrammation_returns_null_when_seance_not_found(): void
    {
        $klassciMock = Mockery::mock(KlassciProxyService::class);
        $klassciMock->shouldReceive('requestWithUserToken')
            ->andReturn(['data' => ['matieres' => []]]);

        $service = new SeanceQueryService($klassciMock);

        $user = (new User())->forceFill([
            'id' => 1,
            'role' => 'enseignant',
            'klassci_token' => 'fake-token',
            'klassci_id' => 99,
            'name' => 'Test Teacher',
            'email' => 'teacher@example.com',
        ]);

        self::assertNull($service->getProgrammation(42, $user));
    }

    public function test_getProgrammation_propagates_no_token_exception(): void
    {
        $klassciMock = Mockery::mock(KlassciProxyService::class);
        $service = new SeanceQueryService($klassciMock);

        $user = (new User())->forceFill([
            'id' => 1,
            'role' => 'enseignant',
            'klassci_token' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $service->getProgrammation(42, $user);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
