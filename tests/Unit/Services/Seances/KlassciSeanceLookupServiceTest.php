<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seances;

use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciSeanceLookupService;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Tests\TestCase;

#[CoversClass(KlassciSeanceLookupService::class)]
final class KlassciSeanceLookupServiceTest extends TestCase
{
    private const TOKEN = 'klassci-token';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_teacher_lookup_ignores_malformed_matieres_and_returns_matching_seance(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('requestWithUserToken')
            ->once()
            ->with(self::TOKEN, 'me/teacher-dashboard', 'GET')
            ->andReturn([
                'data' => [
                    'matieres' => [
                        ['nom' => 'missing id'],
                        ['id' => 17, 'nom' => 'Mathematiques'],
                    ],
                ],
            ]);
        $klassci->shouldReceive('requestWithUserToken')
            ->once()
            ->with(self::TOKEN, 'matieres/17', 'GET')
            ->andReturn([
                'data' => [
                    'matiere' => ['id' => 17, 'nom' => 'Mathematiques'],
                    'seances_programmees' => [
                        ['id' => '44', 'titre' => 'Algebre'],
                    ],
                ],
            ]);

        [$seance, $matiere] = $this->service($klassci)
            ->lookup(44, $this->user('enseignant'), self::TOKEN);

        self::assertSame('44', $seance['id'] ?? null);
        self::assertSame(17, $matiere['id'] ?? null);
        self::assertSame([
            'id' => 501,
            'nom' => 'Ada Teacher',
            'email' => 'ada@example.test',
        ], $seance['enseignant'] ?? null);
    }

    public function test_student_lookup_uses_nested_matiere_id_and_first_teacher_payload(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('requestWithUserToken')
            ->once()
            ->with(self::TOKEN, 'me/dashboard', 'GET')
            ->andReturn([
                'data' => [
                    'cours' => [
                        ['matiere' => ['id' => '22']],
                    ],
                ],
            ]);
        $klassci->shouldReceive('requestWithUserToken')
            ->once()
            ->with(self::TOKEN, 'matieres/22', 'GET')
            ->andReturn([
                'data' => [
                    'matiere' => ['id' => 22, 'nom' => 'Physique'],
                    'enseignants' => [
                        ['id' => 9, 'nom' => 'Grace Hopper'],
                    ],
                    'seances_programmees' => [
                        ['id' => 55, 'titre' => 'Optique'],
                    ],
                ],
            ]);

        [$seance, $matiere] = $this->service($klassci)
            ->lookup(55, $this->user('etudiant'), self::TOKEN);

        self::assertSame(55, $seance['id'] ?? null);
        self::assertSame(22, $matiere['id'] ?? null);
        self::assertSame(['id' => 9, 'nom' => 'Grace Hopper'], $seance['enseignant'] ?? null);
    }

    private function service(KlassciProxyService $klassci): KlassciSeanceLookupService
    {
        return new KlassciSeanceLookupService(new NullLogger, $klassci);
    }

    private function user(string $role): User
    {
        $user = new User;
        $user->role = $role;
        $user->klassci_id = 501;
        $user->name = 'Ada Teacher';
        $user->email = 'ada@example.test';

        return $user;
    }

    private function mockKlassci(): KlassciProxyService&MockInterface
    {
        /** @var KlassciProxyService&MockInterface $klassci */
        $klassci = Mockery::mock(KlassciProxyService::class);

        return $klassci;
    }
}
