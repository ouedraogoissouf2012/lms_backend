<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seances;

use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciSeanceLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Depuis la refonte #517, l'algorithme de recherche (fast-path local résolu
 * via `seances.klassci_matiere_id` + fallback batché) vit dans
 * {@see \App\Services\Seances\KlassciSeanceMatiereScanner} — testé
 * séparément (`KlassciSeanceMatiereScannerTest`). Ce test exerce la chaîne
 * réelle (container + `KlassciSeanceMatiereScanner` + `LocalSeanceMatiereResolver`
 * non mockés, base vide -> résolution locale absente -> chemin batch), seul
 * `KlassciProxyService` (frontière externe réelle) est mocké — même
 * convention que `UpcomingSeancesNoNPlusOneTest`.
 */
#[CoversClass(KlassciSeanceLookupService::class)]
final class KlassciSeanceLookupServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'klassci-token';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_teacher_lookup_ignores_malformed_matieres_and_returns_matching_seance(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
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
            // Le fallback batch ne fetch QUE l'id résoluble (17) : la matière
            // malformée sans id est écartée avant tout appel réseau.
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->once()
                ->with([17], self::TOKEN)
                ->andReturn([
                    17 => [
                        'data' => [
                            'matiere' => ['id' => 17, 'nom' => 'Mathematiques'],
                            'seances_programmees' => [
                                ['id' => '44', 'titre' => 'Algebre'],
                            ],
                        ],
                    ],
                ]);
        });

        [$seance, $matiere] = app(KlassciSeanceLookupService::class)
            ->lookup(44, $this->user('enseignant'), self::TOKEN);

        self::assertSame('44', $seance['id'] ?? null);
        self::assertSame(17, $matiere['id'] ?? null);
        self::assertSame([
            'id' => 501,
            'nom' => 'Ada Teacher',
            'email' => 'ada@example.test',
        ], $seance['enseignant'] ?? null);
    }

    public function test_teacher_lookup_returns_null_pair_when_seance_not_found(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with(self::TOKEN, 'me/teacher-dashboard', 'GET')
                ->andReturn(['data' => ['matieres' => [['id' => 17]]]]);
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->once()
                ->with([17], self::TOKEN)
                ->andReturn([17 => ['data' => ['seances_programmees' => []]]]);
        });

        [$seance, $matiere] = app(KlassciSeanceLookupService::class)
            ->lookup(44, $this->user('enseignant'), self::TOKEN);

        self::assertNull($seance);
        self::assertNull($matiere);
    }

    public function test_student_lookup_uses_nested_matiere_id_and_first_teacher_payload(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with(self::TOKEN, 'me/dashboard', 'GET')
                ->andReturn([
                    'data' => [
                        'cours' => [
                            ['matiere' => ['id' => '22']],
                        ],
                    ],
                ]);
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->once()
                ->with([22], self::TOKEN)
                ->andReturn([
                    22 => [
                        'data' => [
                            'matiere' => ['id' => 22, 'nom' => 'Physique'],
                            'enseignants' => [
                                ['id' => 9, 'nom' => 'Grace Hopper'],
                            ],
                            'seances_programmees' => [
                                ['id' => 55, 'titre' => 'Optique'],
                            ],
                        ],
                    ],
                ]);
        });

        [$seance, $matiere] = app(KlassciSeanceLookupService::class)
            ->lookup(55, $this->user('etudiant'), self::TOKEN);

        self::assertSame(55, $seance['id'] ?? null);
        self::assertSame(22, $matiere['id'] ?? null);
        self::assertSame(['id' => 9, 'nom' => 'Grace Hopper'], $seance['enseignant'] ?? null);
    }

    public function test_student_lookup_falls_back_to_matiere_enseignant_when_enseignants_list_is_malformed(): void
    {
        // Régression : un item non-array dans 'enseignants' (payload KLASSCI
        // malformé) ne doit PAS compter comme "un enseignant présent" — sinon
        // $seanceTrouvee['enseignant'] est écrasé par [] au lieu du fallback.
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with(self::TOKEN, 'me/dashboard', 'GET')
                ->andReturn(['data' => ['cours' => [['matiere' => ['id' => 22]]]]]);
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->once()
                ->with([22], self::TOKEN)
                ->andReturn([
                    22 => [
                        'data' => [
                            'matiere' => ['id' => 22, 'nom' => 'Physique', 'enseignant' => ['id' => 9, 'nom' => 'Grace Hopper']],
                            'enseignants' => [null],
                            'seances_programmees' => [['id' => 55]],
                        ],
                    ],
                ]);
        });

        [$seance] = app(KlassciSeanceLookupService::class)
            ->lookup(55, $this->user('etudiant'), self::TOKEN);

        self::assertSame(['id' => 9, 'nom' => 'Grace Hopper'], $seance['enseignant'] ?? null);
    }

    public function test_student_lookup_returns_null_pair_when_dashboard_call_throws(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with(self::TOKEN, 'me/dashboard', 'GET')
                ->andThrow(new \RuntimeException('KLASSCI indisponible'));
        });

        [$seance, $matiere] = app(KlassciSeanceLookupService::class)
            ->lookup(55, $this->user('etudiant'), self::TOKEN);

        self::assertNull($seance);
        self::assertNull($matiere);
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
}
