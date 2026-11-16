<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1113 : une évaluation VERROUILLÉE (une copie remise, ou le drapeau `is_locked`)
 * refusait la modification et la suppression en 403 sans motif. Le front affichait
 * alors « vous n'avez pas les droits », ce qui est faux : ce sont les copies qui
 * verrouillent. Le refus devient 409 `evaluation_locked`.
 *
 * Les deux types d'école, vraie route, vrai jeton.
 */
final class EvaluationVerrouilleeMotifTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return iterable<string, array{InstitutionMode}> */
    public static function typesDEcole(): iterable
    {
        yield 'école créée depuis le LMS' => [InstitutionMode::Standalone];
        yield 'école créée depuis KLASSCI' => [InstitutionMode::Klassci];
    }

    #[DataProvider('typesDEcole')]
    public function test_une_evaluation_avec_une_copie_refuse_modification_et_suppression_avec_son_motif(InstitutionMode $mode): void
    {
        [$auteur, $evaluation, $ecole] = $this->evaluationEn($mode);
        EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->getKey(),
            'student_id' => $this->membre($ecole, 'etudiant')->getKey(),
            'institution_id' => $ecole->getKey(),
        ]);

        $this->assertVerrouillee($this->asTenant($auteur)->putJson($this->de($evaluation), ['titre' => 'Nouveau titre']));
        $this->assertVerrouillee($this->asTenant($auteur)->deleteJson($this->de($evaluation)));

        $evaluation->refresh();
        self::assertSame('Contrôle 1', $evaluation->titre, 'rien n est modifié');
        self::assertFalse($evaluation->trashed(), 'rien n est supprimé');
    }

    /** Le drapeau `is_locked` seul, sans copie, verrouille de la même façon. */
    #[DataProvider('typesDEcole')]
    public function test_le_drapeau_de_verrou_seul_donne_le_meme_motif(InstitutionMode $mode): void
    {
        [$auteur, $evaluation] = $this->evaluationEn($mode);
        $evaluation->forceFill(['is_locked' => true, 'locked_at' => now()])->save();

        $this->assertVerrouillee($this->asTenant($auteur)->putJson($this->de($evaluation), ['titre' => 'Nouveau titre']));
        $this->assertVerrouillee($this->asTenant($auteur)->deleteJson($this->de($evaluation)));
    }

    /** Témoin : sans copie ni drapeau, la modification et la suppression passent. */
    #[DataProvider('typesDEcole')]
    public function test_sans_copie_la_modification_et_la_suppression_passent(InstitutionMode $mode): void
    {
        [$auteur, $evaluation] = $this->evaluationEn($mode);

        $this->asTenant($auteur)->putJson($this->de($evaluation), ['titre' => 'Nouveau titre'])->assertOk();
        $this->asTenant($auteur)->deleteJson($this->de($evaluation))->assertOk();
        self::assertTrue($evaluation->refresh()->trashed());
    }

    /** @param  TestResponse<Response>  $reponse */
    private function assertVerrouillee(TestResponse $reponse): void
    {
        $reponse->assertStatus(409)->assertJsonPath('success', false)->assertJsonPath('reason', 'evaluation_locked');
    }

    /**
     * L'auteur, son évaluation et son école, dans le type d'école donné (même
     * harnais que MiseAJourParListeBlancheTest).
     *
     * @return array{0: User, 1: Evaluation, 2: Institution}
     */
    private function evaluationEn(InstitutionMode $mode): array
    {
        $ecole = $this->ecole($mode);
        $auteur = $this->membre($ecole, 'enseignant');
        $identite = $mode === InstitutionMode::Klassci
            ? ['klassci_enseignant_id' => 42, 'enseignant_id' => null]
            : ['klassci_enseignant_id' => null, 'enseignant_id' => $auteur->getKey()];
        if ($mode === InstitutionMode::Klassci) {
            $auteur->forceFill(['klassci_enseignant_id' => 42, 'klassci_token' => 'jeton'])->save();
        }

        $evaluation = Evaluation::factory()->create($identite + [
            'institution_id' => $ecole->getKey(), 'titre' => 'Contrôle 1', 'type' => 'qcm', 'status' => 'brouillon',
            'is_published' => false, 'is_locked' => false, 'locked_at' => null, 'classe_nom' => '6e A',
            'matiere_nom' => 'Maths', 'enseignant_nom' => 'Auteur', 'date_evaluation' => now()->addDay(),
        ]);
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey()]);

        return [$auteur, $evaluation, $ecole];
    }

    private function de(Evaluation $evaluation): string
    {
        return '/api/evaluations/'.$evaluation->getKey();
    }
}
