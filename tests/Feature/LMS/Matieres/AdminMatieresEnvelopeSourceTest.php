<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Matieres;

use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * `GET /api/admin/matieres` — source des données et honnêteté des mesures.
 *
 * ## Mesure à l'origine de ces tests
 *
 * L'écran affichait « Impossible de charger les matières » : la requête ne
 * répondait pas sous 90 s. Comptage des traces « KLASSCI API Request » pour un
 * seul `request_id` : **198 appels amont** (1 liste + 197 détails), à ~601 ms
 * l'unité. Projeté aux 452 matières de l'établissement : **~272 s**.
 *
 * Or `GET matieres` porte DÉJÀ `combinaisons` pour chaque matière — vérifié
 * contre `matieres/{id}` sur les 4 matières qui en ont : mêmes éléments, seul
 * l'ordre diffère. Les 452 appels de détail n'apportaient donc rien.
 *
 * ## Second défaut, invisible à l'œil
 *
 * Le contrôleur lisait `heures_total`, `nb_seances_programmees`, `nb_lecons` et
 * `nb_evaluations` à la racine de chaque matière. **Aucune de ces clés n'existe**
 * dans le payload KLASSCI : les heures vivent sous `heures.total`, le compte
 * d'évaluations sous `lms_metadata.total_evaluations`. Les quatre valeurs
 * tombaient donc systématiquement sur leur `?? 0`, et les statistiques globales
 * affichaient `total_heures: 0` / `total_seances: 0` — des zéros qui se faisaient
 * passer pour des mesures.
 *
 * Ce qui n'est pas mesurable sans le N+1 (séances programmées, leçons) vaut
 * désormais `null`, à rendre « — » : une donnée absente ne doit jamais se
 * présenter comme un comptage à zéro.
 *
 * @see app/Http/Controllers/API/LMS/LMSMatieresAdminController.php
 */
final class AdminMatieresEnvelopeSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    private function actingAsAdmin(): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs(User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'admin',
            'klassci_token' => 'fake-token',
        ]));
    }

    /**
     * Forme RÉELLE d'un élément de `GET matieres` (relevée sur l'établissement
     * « presentation ») : les heures sont un objet, les compteurs LMS vivent
     * sous `lms_metadata`, et `combinaisons` est déjà là.
     *
     * @return array<string, mixed>
     */
    private function matiereFromKlassci(): array
    {
        return [
            'id' => 2,
            'nom' => 'Mathématiques',
            'code' => 'MATH',
            'description' => 'Analyse',
            'coefficient' => 2,
            'couleur' => '#6366f1',
            'type_formation' => 'initiale',
            'heures' => ['cm' => 50, 'td' => 10, 'tp' => 20, 'stage' => 100, 'total' => 180],
            'combinaisons' => [
                ['filiere' => ['id' => 1, 'nom' => 'BATIMENT', 'code' => 'BTP'],
                 'niveau' => ['id' => 1, 'nom' => 'BTS 1ere ANNEE', 'code' => '1A']],
            ],
            'enseignants' => [],
            'lms_metadata' => ['has_online_courses' => false, 'last_course_date' => null, 'total_evaluations' => 10],
        ];
    }

    private function mockKlassciListOnly(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $m): void {
            $m->shouldReceive('requestWithUserToken')
                ->with('fake-token', 'matieres', 'GET')
                ->once()
                ->andReturn(['data' => [$this->matiereFromKlassci()]]);

            // Le coeur du correctif : plus AUCUN appel de détail par matière.
            $m->shouldReceive('requestWithUserToken')
                ->withArgs(fn ($token, $url, $method) => str_starts_with((string) $url, 'matieres/'))
                ->never();
        });
    }

    public function test_does_not_fetch_details_per_matiere(): void
    {
        $this->actingAsAdmin();
        $this->mockKlassciListOnly();

        $this->getJson('/api/admin/matieres')->assertStatus(200);
    }

    public function test_combinaisons_come_from_the_list_payload(): void
    {
        $this->actingAsAdmin();
        $this->mockKlassciListOnly();

        $this->getJson('/api/admin/matieres')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.matieres.0.combinaisons')
            ->assertJsonPath('data.matieres.0.combinaisons.0.filiere.nom', 'BATIMENT');
    }

    public function test_hours_and_evaluations_are_read_where_klassci_puts_them(): void
    {
        $this->actingAsAdmin();
        $this->mockKlassciListOnly();

        $this->getJson('/api/admin/matieres')
            ->assertStatus(200)
            // `heures.total`, pas `heures_total` (clé inexistante -> 0 fabriqué).
            ->assertJsonPath('data.matieres.0.heures_total', 180)
            // `lms_metadata.total_evaluations`, pas `nb_evaluations`.
            ->assertJsonPath('data.matieres.0.nb_evaluations', 10);
    }

    public function test_unmeasurable_counters_are_null_never_zero(): void
    {
        $this->actingAsAdmin();
        $this->mockKlassciListOnly();

        $this->getJson('/api/admin/matieres')
            ->assertStatus(200)
            // Non fournis par la liste : `null` (rendu « — »), jamais `0`, qui
            // se lirait comme « cette matière n'a aucune séance programmée ».
            ->assertJsonPath('data.matieres.0.nb_seances_programmees', null)
            ->assertJsonPath('data.matieres.0.nb_lecons', null);
    }

    public function test_global_statistics_sum_what_is_measured_and_null_what_is_not(): void
    {
        $this->actingAsAdmin();
        $this->mockKlassciListOnly();

        $this->getJson('/api/admin/matieres')
            ->assertStatus(200)
            ->assertJsonPath('data.statistiques.total', 1)
            ->assertJsonPath('data.statistiques.total_heures', 180)
            ->assertJsonPath('data.statistiques.total_seances', null);
    }
}
