<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * La réponse 200 de `POST /evaluations/{id}/submissions/{submission}/grade` suit la spec
 * (lot C des `allOf`).
 *
 * Avant : `data` était décrit par un `allOf` (`EvaluationSubmissionRecord` plus sa
 * relation `student`), que le validateur du dépôt refuse. Désormais : la référence au
 * SEUL composant `EvaluationSubmissionRecord`, qui déclare ses relations chargées.
 *
 * Vraie route, vrai jeton (`asTenant`), école créée depuis le LMS.
 */
final class NotationDeCopieSuitLaSpecTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    public function test_la_notation_d_une_dissertation_suit_la_spec(): void
    {
        $ecole = $this->ecole();
        $enseignant = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $eleve = $this->membre($ecole, 'etudiant');
        $this->inscrire($eleve, $classe, 'actif');
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $ecole->getKey(),
            'classe_id' => $classe->getKey(), 'matiere_id' => $maths->getKey(), 'enseignant_id' => $enseignant->getKey(),
            'klassci_evaluation_id' => null, 'klassci_classe_id' => null, 'klassci_matiere_id' => null, 'klassci_enseignant_id' => null,
            'is_published' => true, 'status' => 'planifiee', 'notes_published' => false,
        ]);
        $dissertation = EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->getKey(), 'type' => 'dissertation', 'points' => 10,
        ]);
        $copie = EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->getKey(), 'institution_id' => $ecole->getKey(),
            'student_id' => $eleve->getKey(), 'klassci_etudiant_id' => null, 'attempt' => 1,
            'status' => 'soumis', 'score' => '0.00', 'note_sur_20' => '0.00', 'submitted_at' => now(),
            'answers' => [(string) $dissertation->getKey() => 'Une dissertation'],
        ]);

        $reponse = $this->asTenant($enseignant)->postJson(
            '/api/evaluations/'.$evaluation->getKey().'/submissions/'.$copie->getKey().'/grade',
            ['points' => [(string) $dissertation->getKey() => 8]],
        );

        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/submissions/{submission}/grade', 'post');
        $reponse->assertOk()->assertJsonPath('data.status', 'corrige')->assertJsonPath('data.student.id', $eleve->getKey());
    }
}
