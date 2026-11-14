<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Concerns;

use App\Http\Requests\Concerns\ChecksEvaluationOwnership;
use App\Models\Evaluation;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * `ChecksEvaluationOwnership`, par la classe et sans route : un compte sans
 * établissement ne désigne aucune évaluation, pas même une évaluation sans
 * établissement. La comparaison ne s'écrit jamais `institution_id IS NULL`.
 * Défense en profondeur : la règle tient d'elle-même, quelle que soit la garde
 * placée devant elle.
 */
final class EvaluationSansEtablissementTest extends TestCase
{
    use RefreshDatabase;

    /** L'auteur d'une évaluation sans établissement, lui-même sans établissement : refusé. */
    public function test_l_auteur_sans_etablissement_ne_designe_pas_son_evaluation(): void
    {
        $auteur = $this->compteSansEtablissement('enseignant', 42);
        $evaluation = $this->evaluationSansEtablissement(['enseignant_id' => $auteur->id, 'klassci_enseignant_id' => 42]);

        self::assertFalse($this->autorise(new MutationDEvaluationRequest, $auteur, $evaluation));
    }

    /** Le contournement d'un rôle d'administration n'ouvre rien sans établissement. */
    public function test_un_role_d_administration_sans_etablissement_est_refuse(): void
    {
        $administrateur = $this->compteSansEtablissement('superAdmin', null);
        $evaluation = $this->evaluationSansEtablissement(['klassci_enseignant_id' => 999]);

        self::assertFalse($this->autorise(new MutationDEvaluationRequest, $administrateur, $evaluation));
    }

    /** La lecture des notes par un coordinateur, même règle. */
    public function test_un_coordinateur_sans_etablissement_ne_lit_rien(): void
    {
        $coordinateur = $this->compteSansEtablissement('coordinateur', null);
        $evaluation = $this->evaluationSansEtablissement(['klassci_enseignant_id' => 999]);

        self::assertFalse($this->autorise(new LectureDEvaluationRequest, $coordinateur, $evaluation));
    }

    private function compteSansEtablissement(string $role, ?int $klassciEnseignantId): User
    {
        return User::factory()->create([
            'institution_id' => null,
            'role' => $role,
            'klassci_role' => $role,
            'klassci_enseignant_id' => $klassciEnseignantId,
        ]);
    }

    /** @param array<string, mixed> $attributs */
    private function evaluationSansEtablissement(array $attributs): Evaluation
    {
        return Evaluation::factory()->create(['institution_id' => null] + $attributs);
    }

    /** La requête liée à `/evaluations/{id}`, l'appelant authentifié comme le ferait Sanctum. */
    private function autorise(FormRequest $requete, User $appelant, Evaluation $evaluation): bool
    {
        $this->actingAs($appelant);
        $requete->initialize(server: ['REQUEST_URI' => "/evaluations/{$evaluation->id}", 'REQUEST_METHOD' => 'DELETE']);
        $route = new Route(['DELETE'], '/evaluations/{id}', []);
        $route->bind($requete);
        $route->parameters = ['id' => (string) $evaluation->id];
        $requete->setRouteResolver(static fn (): Route => $route);
        $requete->setUserResolver(static fn (): User => $appelant);
        $requete->setContainer(app());

        return $requete->authorize();
    }
}

/** @internal La voie de MUTATION du trait. */
final class MutationDEvaluationRequest extends FormRequest
{
    use ChecksEvaluationOwnership;

    public function authorize(): bool
    {
        return $this->checkEvaluationOwnership();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}

/** @internal La voie de LECTURE du trait. */
final class LectureDEvaluationRequest extends FormRequest
{
    use ChecksEvaluationOwnership;

    public function authorize(): bool
    {
        return $this->checkEvaluationReadAccess();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
