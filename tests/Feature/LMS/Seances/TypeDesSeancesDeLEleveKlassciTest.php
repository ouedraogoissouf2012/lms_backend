<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\StudentClassesSeancesFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * #1668 — les séances de l'élève d'une école KLASSCI (« mes classes ») portent le type que
 * KLASSCI donne dans `type_cours`, clé relevée en production le 2026-11-25 (D1411). Seules
 * CM, TD et TP sont reprises ; toute autre valeur donne `null`.
 */
final class TypeDesSeancesDeLEleveKlassciTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'fake-token';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_le_type_cours_connu_est_repris_un_autre_donne_null(): void
    {
        $eleve = User::factory()->for(Institution::factory()->create())->create([
            'role' => 'etudiant',
            'klassci_id' => 555,
            'klassci_token' => self::TOKEN,
        ]);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with(self::TOKEN, 'me/dashboard', 'GET')
                ->andReturn(['data' => [
                    'classe' => ['id' => 700],
                    'cours' => [['id' => 2, 'nom' => 'Algorithme', 'code' => 'ID456789']],
                ]]);
            $mock->shouldReceive('getEmploiTemps')
                ->andReturn(['data' => [
                    $this->seance(900_301, 'CM'),
                    $this->seance(900_302, 'course'),
                ]]);
        });

        $seances = app(StudentClassesSeancesFetcher::class)->fetch($eleve, self::TOKEN, '2026-08-01', '2026-08-31');

        self::assertInstanceOf(Collection::class, $seances);
        self::assertSame(
            [900_301 => 'cm', 900_302 => null],
            $seances->pluck('type_seance', 'id')->all(),
        );
    }

    /** @return array<string, mixed> */
    private function seance(int $id, string $typeCours): array
    {
        return [
            'id' => $id,
            'matiere' => ['id' => 2],
            'classe' => ['id' => 700, 'nom' => 'B2 COM'],
            'type_cours' => $typeCours,
            'programmation' => [
                'date' => '2026-08-15',
                'heure_debut' => '2026-08-15T09:00:00.000000Z',
                'heure_fin' => '2026-08-15T10:00:00.000000Z',
            ],
        ];
    }
}
