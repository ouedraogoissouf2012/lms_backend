<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Concerns;

use App\Http\Requests\Concerns\ChecksEvaluationOwnership;
use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * `ChecksEvaluationOwnership`, par la classe et sans route : un compte sans
 * établissement ne désigne aucune évaluation. La comparaison ne s'écrit jamais
 * `institution_id IS NULL`.
 *
 * #801 : une évaluation sans établissement n'existe plus (`institution_id` est
 * obligatoire) ; l'évaluation est donc portée par une école. Le compte, lui, peut
 * encore n'en avoir aucune (`users` reste facultative pour le supradmin) : c'est
 * ce que ce fichier garde.
 * Défense en profondeur : la règle tient d'elle-même, quelle que soit la garde
 * placée devant elle.
 *
 * #1782 : sans établissement, aucune évaluation n'est dans la portée : elle est
 * INTROUVABLE (404), avant tout contrôle de droit, et non plus refusée en 403.
 */
final class EvaluationSansEtablissementTest extends TestCase
{
    use RefreshDatabase;

    /** L'auteur, sans établissement, d'une évaluation : elle lui est introuvable. */
    public function test_l_auteur_sans_etablissement_ne_designe_pas_son_evaluation(): void
    {
        $auteur = $this->compteSansEtablissement('enseignant', 42);
        $evaluation = $this->evaluationDUneEcole(['enseignant_id' => $auteur->id, 'klassci_enseignant_id' => 42]);

        self::assertTrue($this->introuvable(new MutationDEvaluationRequest, $auteur, $evaluation));
    }

    /** Le contournement d'un rôle d'administration n'ouvre rien sans établissement. */
    public function test_un_role_d_administration_sans_etablissement_est_refuse(): void
    {
        $administrateur = $this->compteSansEtablissement('superAdmin', null);
        $evaluation = $this->evaluationDUneEcole(['klassci_enseignant_id' => 999]);

        self::assertTrue($this->introuvable(new MutationDEvaluationRequest, $administrateur, $evaluation));
    }

    /** La lecture des notes par un coordinateur, même règle. */
    public function test_un_coordinateur_sans_etablissement_ne_lit_rien(): void
    {
        $coordinateur = $this->compteSansEtablissement('coordinateur', null);
        $evaluation = $this->evaluationDUneEcole(['klassci_enseignant_id' => 999]);

        self::assertTrue($this->introuvable(new LectureDEvaluationRequest, $coordinateur, $evaluation));
    }

    private function compteSansEtablissement(string $role, ?int $klassciEnseignantId): User
    {
        // #801 : le seul compte sans établissement est le supradmin ; il est aussi un rôle
        // d'administration. Le rôle demandé ne survit que comme `klassci_role`, pour mémoire.
        return User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
            'klassci_role' => $role,
            'klassci_enseignant_id' => $klassciEnseignantId,
        ]);
    }

    /** @param array<string, mixed> $attributs */
    private function evaluationDUneEcole(array $attributs): Evaluation
    {
        return Evaluation::factory()->create(['institution_id' => Institution::factory()->create()->id] + $attributs);
    }

    /** #1782 : la garde déclare-t-elle l'évaluation introuvable (404) ? Une autorisation rendue n'en est pas une. */
    private function introuvable(FormRequest $requete, User $appelant, Evaluation $evaluation): bool
    {
        try {
            $this->autorise($requete, $appelant, $evaluation);
        } catch (NotFoundHttpException) {
            return true;
        }

        return false;
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
