<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Evaluation\Student\CoefficientsDesMatieres;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1676 : UNE règle de moyenne générale, celle du bilan d'une classe. « Mes notes » la pondère
 * par le coefficient de la MATIÈRE dans la classe (`classe_matiere.coefficient`), à défaut par
 * celui de la matière, à défaut par 1. Avant, c'était la moyenne SIMPLE des matières.
 *
 * Changement visible pour l'élève, DÉCLARÉ : il ne change rien quand tous les coefficients
 * valent 1 (premier test).
 *
 * Vraie route, vrai jeton (`asTenant`).
 */
final class MoyenneGeneralePondereeTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    public function test_avec_tous_les_coefficients_a_un_rien_ne_bouge(): void
    {
        [$eleve, $classe, $maths, $physique] = $this->deuxMatieres(1, 1);
        $this->programme($classe, $maths, 1);
        $this->programme($classe, $physique, 1);

        $reponse = $this->mesNotes($eleve);

        $reponse->assertJsonPath('data.moyenne_generale', 13);
        self::assertSame([['Maths', 10, 1], ['Physique', 16, 1]], $this->lignes($reponse));
    }

    public function test_le_coefficient_de_la_matiere_dans_la_classe_pondere_la_moyenne_generale(): void
    {
        [$eleve, $classe, $maths, $physique] = $this->deuxMatieres(1, 1);
        $this->programme($classe, $maths, 1);
        $this->programme($classe, $physique, 3);

        $reponse = $this->mesNotes($eleve);

        // (10 × 1 + 16 × 3) / 4 = 14,5 ; la moyenne simple donnait 13.
        $reponse->assertJsonPath('data.moyenne_generale', 14.5);
        self::assertSame([['Maths', 10, 1], ['Physique', 16, 3]], $this->lignes($reponse));
    }

    public function test_a_defaut_du_programme_le_coefficient_de_la_matiere_compte(): void
    {
        [$eleve] = $this->deuxMatieres(1, 3);

        $reponse = $this->mesNotes($eleve);

        $reponse->assertJsonPath('data.moyenne_generale', 14.5);
        self::assertSame([['Maths', 10, 1], ['Physique', 16, 3]], $this->lignes($reponse));
    }

    /** Volet KLASSCI : l'évaluation porte des identités KLASSCI, lues sur les miroirs de SON école. */
    public function test_volet_klassci_le_coefficient_est_lu_sur_les_miroirs_de_l_ecole(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $autre = $this->ecole(InstitutionMode::Klassci);
        $classe = Classe::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => 77]);
        $matiere = Matiere::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => 31, 'coefficient' => 2]);
        $this->programme($classe, $matiere, 4);
        // Les mêmes identités KLASSCI dans une AUTRE école : jamais lues.
        $ailleurs = Classe::factory()->create(['institution_id' => $autre->getKey(), 'klassci_id' => 77]);
        $this->programme($ailleurs, Matiere::factory()->create(['institution_id' => $autre->getKey(), 'klassci_id' => 31, 'coefficient' => 5]), 5);

        $deLEcole = $this->evaluationKlassci($ecole, 31, 77);
        $sansMiroir = $this->evaluationKlassci($ecole, 99, 77);
        $horsClasse = $this->evaluationKlassci($ecole, 31, null);

        self::assertSame(
            [$deLEcole->id => 4.0, $sansMiroir->id => CoefficientsDesMatieres::NEUTRE, $horsClasse->id => 2.0],
            app(CoefficientsDesMatieres::class)->pour([$deLEcole, $sansMiroir, $horsClasse]),
        );
    }

    /** @return array{User, Classe, Matiere, Matiere} */
    private function deuxMatieres(int $coefficientMaths, int $coefficientPhysique): array
    {
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');
        $classe = $this->classe($ecole, '6e A');
        $this->inscrire($eleve, $classe, 'actif');
        $maths = $this->matiere($ecole, 'Maths');
        $physique = $this->matiere($ecole, 'Physique');
        $maths->forceFill(['coefficient' => $coefficientMaths])->save();
        $physique->forceFill(['coefficient' => $coefficientPhysique])->save();
        $this->note($this->evaluation($classe, $maths), $eleve, '10.00');
        $this->note($this->evaluation($classe, $physique), $eleve, '16.00');

        return [$eleve, $classe, $maths, $physique];
    }

    private function programme(Classe $classe, Matiere $matiere, int $coefficient): void
    {
        DB::table('classe_matiere')->insert([
            'classe_id' => $classe->getKey(),
            'matiere_id' => $matiere->getKey(),
            'institution_id' => $classe->institution_id,
            'coefficient' => $coefficient,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function mesNotes(User $eleve): TestResponse
    {
        $reponse = $this->asTenant($eleve)->getJson('/api/my-grades');
        $this->assertMatchesOpenApiResponse($reponse, '/my-grades', 'get');

        return $reponse->assertOk();
    }

    /** @return list<array{mixed, mixed, mixed}> [nom, moyenne, coefficient de la matière] */
    private function lignes(TestResponse $reponse): array
    {
        return array_values(array_map(
            static fn (array $m): array => [$m['matiere_nom'], $m['moyenne'], $m['coefficient'] ?? 'absent'],
            (array) $reponse->json('data.matieres'),
        ));
    }

    private function evaluation(Classe $classe, Matiere $matiere): Evaluation
    {
        return $this->avecQcm([
            'institution_id' => $classe->institution_id,
            'classe_id' => $classe->getKey(),
            'matiere_id' => $matiere->getKey(),
            'matiere_nom' => $matiere->libelle,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
        ]);
    }

    private function evaluationKlassci(Institution $ecole, int $matiere, ?int $classe): Evaluation
    {
        return $this->avecQcm([
            'institution_id' => $ecole->getKey(),
            'classe_id' => null,
            'matiere_id' => null,
            'klassci_classe_id' => $classe,
            'klassci_matiere_id' => $matiere,
        ]);
    }

    /** @param  array<string, mixed>  $attributs */
    private function avecQcm(array $attributs): Evaluation
    {
        $evaluation = Evaluation::factory()->create(array_merge([
            'is_published' => true,
            'show_results' => true,
            'status' => 'terminee',
            'date_evaluation' => now()->subDay(),
            'duree_minutes' => 60,
            'coefficient' => '1.00',
            'klassci_evaluation_id' => null,
            'klassci_enseignant_id' => null,
        ], $attributs));
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey(), 'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['a']]);

        return $evaluation;
    }

    private function note(Evaluation $evaluation, User $eleve, string $note): void
    {
        EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->getKey(),
            'institution_id' => $evaluation->institution_id,
            'student_id' => $eleve->getKey(),
            'klassci_etudiant_id' => $eleve->klassci_id,
            'attempt' => 1,
            'status' => 'soumis',
            'feedback' => null,
            'score' => $note,
            'note_sur_20' => $note,
            'submitted_at' => now(),
        ]);
    }
}
