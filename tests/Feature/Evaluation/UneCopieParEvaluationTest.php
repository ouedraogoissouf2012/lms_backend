<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1715 : UNE copie par évaluation et par élève, partout : la plus récente des copies FINALES
 * (`CopiesFinales`, #1676). Avant, « Mes notes » comptait chaque copie finale : une évaluation à
 * plusieurs tentatives (`max_attempts`) pesait plusieurs fois dans la moyenne de l'élève, alors
 * que le bilan de la classe n'en retenait qu'une.
 *
 * Vraie route, vrai jeton (`asTenant`).
 */
final class UneCopieParEvaluationTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    public function test_mes_notes_ne_retient_que_la_copie_la_plus_recente_de_chaque_evaluation(): void
    {
        [$eleve, , $controle, $devoir] = $this->deuxTentatives();

        $reponse = $this->asTenant($eleve)->getJson('/api/my-grades');

        $this->assertMatchesOpenApiResponse($reponse, '/my-grades', 'get');
        $reponse->assertOk()
            ->assertJsonPath('data.total_evaluations', 2)
            ->assertJsonPath('data.matieres.0.total_evaluations', 2)
            // (16 + 10) / 2 : la tentative à 4 du contrôle ne compte plus.
            ->assertJsonPath('data.moyenne_generale', 13);
        self::assertSame(
            // Les plus récentes d'abord : le devoir, puis la seconde tentative du contrôle.
            [[$devoir->id, '10.00'], [$controle->id, '16.00']],
            array_map(static fn (array $l): array => [$l['evaluation_id'], $l['note']], (array) $reponse->json('data.matieres.0.evaluations')),
        );
    }

    /** Le test croisé : pour un même élève, « Mes notes » et le bilan de sa classe disent la même moyenne. */
    public function test_mes_notes_et_le_bilan_de_la_classe_rendent_la_meme_moyenne(): void
    {
        [$eleve, $classe, , , $ecole] = $this->deuxTentatives();
        $coordinateur = $this->membre($ecole, 'coordinateur');

        $mesNotes = $this->asTenant($eleve)->getJson('/api/my-grades')->assertOk()->json('data.moyenne_generale');
        $bilan = $this->asTenant($coordinateur)->getJson('/api/lms/classes/'.$classe->id.'/resultats')->assertOk()->json('data.eleves.0.moyenne_generale');

        self::assertSame(13, $mesNotes);
        self::assertSame($mesNotes, $bilan);
    }

    /** @return array{User, Classe, Evaluation, Evaluation, Institution} */
    private function deuxTentatives(): array
    {
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');
        $classe = $this->classe($ecole, '6e A');
        $this->inscrire($eleve, $classe, 'actif');
        $maths = $this->matiere($ecole, 'Maths');
        $controle = $this->evaluation($classe, $maths, 'Contrôle');
        $devoir = $this->evaluation($classe, $maths, 'Devoir');
        // Deux tentatives finales du contrôle : 4, puis 16 (la plus récente).
        $this->note($controle, $eleve, 1, '4.00', now()->subHours(2));
        $this->note($controle, $eleve, 2, '16.00', now()->subHour());
        $this->note($devoir, $eleve, 1, '10.00', now()->subMinutes(30));

        return [$eleve, $classe, $controle, $devoir, $ecole];
    }

    private function evaluation(Classe $classe, Matiere $matiere, string $titre): Evaluation
    {
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $classe->institution_id, 'classe_id' => $classe->id, 'matiere_id' => $matiere->id,
            'matiere_nom' => $matiere->libelle, 'klassci_classe_id' => null, 'klassci_matiere_id' => null,
            'klassci_evaluation_id' => null, 'klassci_enseignant_id' => null, 'titre' => $titre, 'coefficient' => '1.00',
            'is_published' => true, 'show_results' => true, 'status' => 'terminee', 'max_attempts' => 2,
            'date_evaluation' => $titre === 'Contrôle' ? now()->subDays(3) : now()->subDays(2), 'duree_minutes' => 60,
        ]);
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->id, 'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['a']]);

        return $evaluation;
    }

    private function note(Evaluation $evaluation, User $eleve, int $tentative, string $note, \DateTimeInterface $remise): void
    {
        EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->id, 'institution_id' => $evaluation->institution_id,
            'student_id' => $eleve->id, 'klassci_etudiant_id' => $eleve->klassci_id, 'attempt' => $tentative,
            'status' => 'soumis', 'feedback' => null, 'score' => $note, 'note_sur_20' => $note, 'submitted_at' => $remise,
        ]);
    }
}
