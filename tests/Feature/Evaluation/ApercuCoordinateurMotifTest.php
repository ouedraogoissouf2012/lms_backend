<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\Institution;
use Database\Factories\EvaluationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * `GET /api/evaluations/{id}/preview` : le 403 du coordinateur dit son motif.
 *
 * Avant : un coordinateur qui prévisualisait une évaluation non terminée recevait 403
 * SANS motif, comme le refus de `PreviewEvaluationRequest` (rôle, enseignant non
 * propriétaire, évaluation d'une autre école). Le client ne pouvait pas dire « revenez
 * quand elle sera terminée ». Le motif `preview_requires_finished_evaluation` est posé
 * sur CE SEUL 403.
 *
 * Les deux types d'école. Vraie route, vrai jeton (`asTenant`).
 */
final class ApercuCoordinateurMotifTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const MOTIF = 'preview_requires_finished_evaluation';

    /** @return array<string, array{InstitutionMode}> */
    public static function ecoles(): array
    {
        return [
            'école du LMS' => [InstitutionMode::Standalone],
            'école KLASSCI' => [InstitutionMode::Klassci],
        ];
    }

    #[DataProvider('ecoles')]
    public function test_le_coordinateur_recoit_le_motif_sur_une_evaluation_non_terminee(InstitutionMode $mode): void
    {
        $ecole = $this->ecoleDe($mode);
        $evaluation = $this->evaluation($ecole, Evaluation::factory()->planifiee());

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson($this->de($evaluation));

        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/preview', 'get');
        $reponse->assertForbidden()
            ->assertJsonPath('reason', self::MOTIF)
            ->assertJsonMissingPath('data');
    }

    /**
     * Le témoin : terminée, l'évaluation s'ouvre au coordinateur, sans motif. Sa réponse suit
     * la spec : `options` n'y avait pas de type, et le validateur échouait fermé ; c'est une
     * liste de textes depuis que le serveur l'impose (#1059).
     */
    #[DataProvider('ecoles')]
    public function test_le_coordinateur_previsualise_une_evaluation_terminee(InstitutionMode $mode): void
    {
        $ecole = $this->ecoleDe($mode);
        $evaluation = $this->evaluation($ecole, Evaluation::factory()->terminee());

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson($this->de($evaluation));

        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/preview', 'get');
        $reponse->assertOk()
            ->assertJsonPath('data.id', $evaluation->id)
            ->assertJsonMissingPath('reason');
    }

    /** Le refus de la requête (enseignant non propriétaire) ne porte PAS ce motif, et suit le même schéma. */
    #[DataProvider('ecoles')]
    public function test_le_refus_d_un_collegue_ne_porte_pas_le_motif(InstitutionMode $mode): void
    {
        $ecole = $this->ecoleDe($mode);
        $evaluation = $this->evaluation($ecole, Evaluation::factory()->planifiee());

        $reponse = $this->asTenant($this->membre($ecole, 'enseignant'))->getJson($this->de($evaluation));

        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/preview', 'get');
        $reponse->assertForbidden()->assertJsonMissingPath('reason');
    }

    private function ecoleDe(InstitutionMode $mode): Institution
    {
        if ($mode === InstitutionMode::Klassci) {
            $this->disableKlassciMiddleware();
        }

        return $this->ecole($mode);
    }

    private function evaluation(Institution $ecole, EvaluationFactory $fabrique): Evaluation
    {
        $evaluation = $fabrique->create([
            'institution_id' => $ecole->id,
            'enseignant_id' => $this->membre($ecole, 'enseignant')->id,
        ]);
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->id, 'options' => ['3', '4']]);

        return $evaluation;
    }

    private function de(Evaluation $evaluation): string
    {
        return '/api/evaluations/'.$evaluation->id.'/preview';
    }
}
