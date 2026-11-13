<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Issue #368 — « un élève sans note ne produit pas de 500 ».
 *
 * Fige les gardes zéro-division de `StudentGradesAggregator`
 * (`computeMatiereAverages` : `$totalCoef > 0`, `computeOverallAverage` :
 * `$countMatieres > 0`) au travers de l'endpoint réel GET /api/my-grades.
 * Si un refactoring remplace une garde par une division nue, ces tests
 * échouent en 500 au lieu de laisser l'erreur partir en production.
 *
 * KLASSCI est mocké (getMatieres) : le calcul des moyennes est 100 % local.
 */
final class StudentGradesZeroDataTest extends TestCase
{
    use RefreshDatabase;

    private const KLASSCI_STUDENT_ID = 7777;

    private Institution $institution;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            // Pas de noms de matières KLASSCI : force le repli sur `matiere_nom` local.
            $mock->shouldReceive('getMatieres')->andReturn(['success' => true, 'data' => []]);
        });

        $this->institution = Institution::factory()->create();
        $this->student = User::factory()->student()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => self::KLASSCI_STUDENT_ID,
            'klassci_token' => 'student-klassci-token',
        ]);
    }

    public function test_eleve_sans_aucune_note_recoit_une_moyenne_generale_nulle_pas_un_500(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/my-grades');

        $response->assertStatus(200)->assertExactJson([
            'success' => true,
            'data' => [
                'matieres' => [],
                'moyenne_generale' => null,
                'total_matieres' => 0,
                'total_evaluations' => 0,
            ],
        ]);
    }

    public function test_matiere_dont_toutes_les_evaluations_ont_coefficient_zero_donne_une_moyenne_nulle(): void
    {
        // Chemin exact de la garde `$totalCoef > 0` : une note EXISTE (15/20)
        // mais son coefficient est 0 → sans garde, division 15*0/0 → 500.
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $this->institution->id,
            'is_published' => true,
            // Fixé : la fabrique le tire au hasard, et C30 (E4a) écarte une note cachée.
            'show_results' => true,
            'coefficient' => 0,
            'klassci_matiere_id' => 42,
            'matiere_nom' => 'Mathématiques',
        ]);
        EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->id,
            'klassci_etudiant_id' => self::KLASSCI_STUDENT_ID,
            // L'auteur, comme `/start` l'écrit : sans lui, la fabrique en crée un autre.
            'student_id' => $this->student->id,
            'status' => 'corrige',
            'note_sur_20' => 15,
            'feedback' => null,
        ]);

        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/my-grades');

        $response->assertStatus(200)
            // Rien à moyenner (coefficient total nul) : `null`, jamais un 0 fabriqué (D72, E4a).
            ->assertJsonPath('data.matieres.0.moyenne', null)
            ->assertJsonPath('data.matieres.0.total_evaluations', 1)
            // Sa moyenne n'a aucune mesure : elle n'entre pas dans la moyenne
            // générale, qui n'en a donc aucune non plus. Ni 0, ni NAN, ni 500.
            ->assertJsonPath('data.moyenne_generale', null)
            ->assertJsonPath('data.total_matieres', 1)
            ->assertJsonPath('data.total_evaluations', 1);
    }

    public function test_isolation_multi_tenant_meme_klassci_id_dans_une_autre_institution(): void
    {
        // Les IDs KLASSCI peuvent se recouper entre tenants : un étudiant de
        // l'institution B porte le MÊME klassci_id. Sa note ne doit jamais
        // fuir dans les moyennes de l'étudiant de l'institution A.
        //
        // Vrai bearer token (pas `Sanctum::actingAs`) : l'isolation de cet
        // endpoint repose à 100 % sur le global scope `BelongsToInstitution`,
        // qui n'est actif que si `ResolveInstitution` a résolu le tenant —
        // ce qui exige un header Authorization réel. `actingAs` laisserait le
        // scope en no-op documenté et le test validerait une fuite fictive.
        $institutionB = Institution::factory()->create();
        $evaluationB = Evaluation::factory()->create([
            'institution_id' => $institutionB->id,
            'is_published' => true,
            'coefficient' => 2,
            'klassci_matiere_id' => 42,
            'matiere_nom' => 'Mathématiques',
        ]);
        EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluationB->id,
            'klassci_etudiant_id' => self::KLASSCI_STUDENT_ID,
            // L'auteur, comme `/start` l'écrit : sans lui, la fabrique en crée un autre.
            'student_id' => $this->student->id,
            'status' => 'corrige',
            'note_sur_20' => 20,
            'feedback' => null,
        ]);

        $token = $this->student->createToken('zero-data-test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/my-grades');

        $response->assertStatus(200)
            ->assertJsonPath('data.total_evaluations', 0)
            ->assertJsonPath('data.matieres', [])
            // Rien à moyenner : `null`, jamais un 0 fabriqué (D72, E4a).
            ->assertJsonPath('data.moyenne_generale', null);
    }
}
