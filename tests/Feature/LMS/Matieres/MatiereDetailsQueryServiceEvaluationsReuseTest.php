<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Matieres;

use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Matiere\MatiereDetailsQueryService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * §1.4 PRODUCTION_STANDARDS.md — verrouille bout en bout, à travers le VRAI
 * `MatiereDetailsQueryService` (aucun collaborateur mocké hors la frontière
 * KLASSCI), que l'orchestrateur relie effectivement le payload `matieres/{id}`
 * (récupéré une fois par `MatiereInfoFetcher`) jusqu'à `MatiereEvaluationsFetcher`
 * — pas seulement que le fetcher isolé sait lire des données qu'on lui donne à
 * la main (déjà couvert par `MatiereEvaluationsFetcherTest`).
 *
 * Le mock KLASSCI est STRICT (aucun `byDefault()` catch-all) : tout appel non
 * explicitement attendu — en particulier `GET evaluations` — fait échouer le
 * test avec une erreur Mockery, pas un résultat vide silencieux.
 *
 * @see app/Services/Matiere/MatiereDetailsQueryService.php
 * @see app/Services/Matiere/MatiereEvaluationsFetcher.php
 */
#[CoversClass(MatiereDetailsQueryService::class)]
final class MatiereDetailsQueryServiceEvaluationsReuseTest extends TestCase
{
    use RefreshDatabase;

    private const MATIERE_ID = 5;
    private const TOKEN = 'fake-token-e2e';

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-e2e']);
        app(TenantManager::class)->set($this->institution);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Forme RÉELLE mesurée en direct sur `matieres/{id}` : `data` porte déjà
     * `matiere`, `combinaisons`, `enseignants` et `evaluations` — cette dernière
     * clé est celle que ce correctif réutilise au lieu de la re-demander.
     *
     * @return array<string, mixed>
     */
    private function realKlassciMatiereShape(): array
    {
        return [
            'data' => [
                'matiere' => ['id' => self::MATIERE_ID, 'nom' => 'Anglais', 'code' => 'ID5356'],
                'combinaisons' => [],
                'enseignants' => [['id' => 700, 'nom' => 'Prof Test']],
                'seances_programmees' => [],
                'evaluations' => [
                    ['id' => 321, 'titre' => 'Devoir Anglais', 'matiere' => ['id' => self::MATIERE_ID, 'nom' => 'Anglais']],
                    // Une evaluation d'une AUTRE matiere ne doit pas fuiter (defense conservee).
                    ['id' => 322, 'titre' => 'Devoir Maths', 'matiere' => ['id' => 999, 'nom' => 'Maths']],
                ],
            ],
        ];
    }

    public function test_coordinateur_evaluations_come_from_the_single_matiere_call_no_second_request(): void
    {
        $coordinateur = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'coordinateur',
            'klassci_token' => self::TOKEN,
        ]);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            // Mock STRICT : seul cet appel est attendu. Un chemin coordinateur
            // n'a besoin d'AUCUN autre appel KLASSCI (seances_programmees et
            // evaluations viennent tous deux de ce seul payload).
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with(self::TOKEN, 'matieres/' . self::MATIERE_ID, 'GET')
                ->andReturn($this->realKlassciMatiereShape());
        });

        $result = app(MatiereDetailsQueryService::class)->getDetailsForUser(self::MATIERE_ID, $coordinateur);

        self::assertNotNull($result);
        self::assertCount(1, $result['evaluations_programmees'], 'Une seule des 2 evaluations du payload appartient a cette matiere.');
        self::assertSame(321, $result['evaluations_programmees'][0]['id']);
    }

    public function test_enseignant_evaluations_still_come_from_matiere_call_dashboard_never_asked_for_evaluations(): void
    {
        $enseignant = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
            'klassci_token' => self::TOKEN,
        ]);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with(self::TOKEN, 'matieres/' . self::MATIERE_ID, 'GET')
                ->andReturn($this->realKlassciMatiereShape());

            // Chemin enseignant : le dashboard reste appele (garde de pertinence,
            // cf. MatiereSeancesFetcher), mais UNIQUEMENT lui — jamais `evaluations`.
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with(self::TOKEN, 'me/teacher-dashboard', 'GET')
                ->andReturn(['data' => ['matieres' => [['id' => self::MATIERE_ID]]]]);

            $mock->shouldReceive('fetchManyClassesDetails')
                ->zeroOrMoreTimes()
                ->andReturn([]);
        });

        $result = app(MatiereDetailsQueryService::class)->getDetailsForUser(self::MATIERE_ID, $enseignant);

        self::assertNotNull($result);
        self::assertCount(1, $result['evaluations_programmees']);
        self::assertSame(321, $result['evaluations_programmees'][0]['id']);
    }
}
