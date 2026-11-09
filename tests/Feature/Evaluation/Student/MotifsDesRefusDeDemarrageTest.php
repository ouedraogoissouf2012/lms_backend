<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation\Student;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Chaque refus de démarrer une évaluation (403) porte un motif stable, que le
 * client traduit (`reason`, #906). Le statut et le message ne changent pas.
 *
 * | Refus | `reason` |
 * |---|---|
 * | fenêtre fermée | `evaluation_window_closed` |
 * | nombre maximum de tentatives atteint | `evaluation_max_attempts` |
 * | reprise interdite | `evaluation_retake_forbidden` |
 * | évaluation qui ne se passe pas en ligne | `evaluation_offline_only` |
 *
 * Ces refus n'existent que dans l'école créée depuis KLASSCI : dans l'école
 * créée depuis le LMS, le démarrage s'arrête avant, faute de jeton KLASSCI
 * (dernier test). Vraie route, vrai jeton porteur, états réels. Chaque réponse
 * suit `docs/openapi.yaml`, dont le schéma du 403 est fermé.
 */
final class MotifsDesRefusDeDemarrageTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/evaluations/{id}/start';

    /** La classe KLASSCI de l'élève, que `me/dashboard` rend et que portent ses évaluations. */
    private const CLASSE = 4242;

    private Institution $ecole;

    private User $eleve;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->ecole = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
        $this->eleve = User::factory()->student()->create([
            'institution_id' => $this->ecole->id,
            'klassci_id' => 6010,
            'klassci_token' => 'jeton-eleve',
        ]);
    }

    public function test_une_fenetre_fermee_porte_son_motif(): void
    {
        $evaluation = $this->evaluation([]);
        // Date au format que la spec déclare (`date-time`, EvaluationWindow).
        $fenetre = ['is_open' => false, 'has_started' => true, 'has_ended' => true, 'end_at' => '2020-01-01T10:00:00+00:00'];
        $this->klassciRend($evaluation, $fenetre);

        $reponse = $this->demarrer($evaluation)->assertStatus(403)->assertExactJson([
            'success' => false,
            'message' => 'L\'évaluation est fermée depuis le 01/01/2020 à 10:00',
            'window' => $fenetre,
            'reason' => 'evaluation_window_closed',
        ]);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    public function test_le_quota_atteint_porte_son_motif(): void
    {
        $evaluation = $this->evaluation(['allow_retake' => true, 'max_attempts' => 1]);
        $this->copieRendue($evaluation);
        $this->klassciRend($evaluation, null);

        $reponse = $this->demarrer($evaluation)->assertStatus(403)->assertExactJson([
            'success' => false,
            'message' => 'Nombre maximum de tentatives atteint (1)',
            'reason' => 'evaluation_max_attempts',
        ]);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    public function test_une_reprise_interdite_porte_son_motif(): void
    {
        $evaluation = $this->evaluation(['allow_retake' => false, 'max_attempts' => 3]);
        $this->copieRendue($evaluation);
        $this->klassciRend($evaluation, null);

        $reponse = $this->demarrer($evaluation)->assertStatus(403)->assertExactJson([
            'success' => false,
            'message' => 'Les reprises ne sont pas autorisées pour cette évaluation.',
            'reason' => 'evaluation_retake_forbidden',
        ]);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    public function test_une_evaluation_hors_ligne_porte_son_motif(): void
    {
        $evaluation = $this->evaluation(['is_online' => false]);
        $this->klassciRend($evaluation, null);

        $reponse = $this->demarrer($evaluation)->assertStatus(403)->assertExactJson([
            'success' => false,
            'message' => 'Cette évaluation ne se passe pas en ligne.',
            'reason' => 'evaluation_offline_only',
        ]);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    /** Le refus d'un appelant qui n'est pas élève reste générique, sans motif, et suit la spec. */
    public function test_un_appelant_qui_n_est_pas_eleve_recoit_un_refus_sans_motif(): void
    {
        $evaluation = $this->evaluation([]);
        $enseignant = User::factory()->create(['institution_id' => $this->ecole->id, 'role' => 'enseignant', 'klassci_token' => 'jeton-prof']);

        $reponse = $this->asTenant($enseignant)->postJson("/api/evaluations/{$evaluation->id}/start")->assertStatus(403);

        self::assertNull($reponse->json('reason'));
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    /** Le schéma du 403 mord : privé de `message`, ou augmenté d'une clé non décrite, il refuse. */
    public function test_le_schema_du_refus_mord(): void
    {
        $evaluation = $this->evaluation(['is_online' => false]);
        $this->klassciRend($evaluation, null);

        $this->assertOpenApiSchemaRefuseSans($this->demarrer($evaluation)->assertStatus(403), self::CHEMIN, 'post', 'message');
    }

    /**
     * École créée depuis le LMS : aucun jeton KLASSCI, le démarrage s'arrête au
     * 401 avant tout refus de démarrage. L'évaluation est hors ligne : si le
     * démarrage allait plus loin, il rendrait `evaluation_offline_only`.
     */
    public function test_dans_l_ecole_creee_depuis_le_lms_aucun_de_ces_motifs_n_est_atteint(): void
    {
        $this->ecole = Institution::factory()->standalone()->create(['is_active' => true]);
        $this->eleve = User::factory()->student()->create([
            'institution_id' => $this->ecole->id,
            'klassci_id' => null,
            'klassci_token' => null,
        ]);
        $evaluation = $this->evaluation(['is_online' => false]);
        $this->mock(KlassciProxyService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('requestWithUserToken'));

        $reponse = $this->demarrer($evaluation)->assertStatus(401);

        self::assertNotContains($reponse->json('reason'), [
            'evaluation_window_closed', 'evaluation_max_attempts', 'evaluation_retake_forbidden', 'evaluation_offline_only',
        ]);
    }

    /** @param  array<string, mixed>  $attributs */
    private function evaluation(array $attributs): Evaluation
    {
        $evaluation = Evaluation::factory()->planifiee()->create($attributs + [
            'institution_id' => $this->ecole->id,
            'klassci_classe_id' => self::CLASSE,
            'klassci_evaluation_id' => 6011,
            'is_published' => true,
            'is_online' => true,
            'allow_retake' => true,
            'max_attempts' => 3,
        ]);
        EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->id,
            'institution_id' => $this->ecole->id,
            'type' => 'qcm',
        ]);

        return $evaluation;
    }

    private function copieRendue(Evaluation $evaluation): void
    {
        EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->id,
            'institution_id' => $this->ecole->id,
            'klassci_etudiant_id' => $this->eleve->klassci_id,
            'status' => 'soumis',
        ]);
    }

    /**
     * `me/dashboard` rend la classe de l'élève ; `evaluations`, l'évaluation
     * avec la fenêtre donnée (`null` : aucune fenêtre, le contrôle passe).
     *
     * @param  ?array<string, mixed>  $fenetre
     */
    private function klassciRend(Evaluation $evaluation, ?array $fenetre): void
    {
        $ligne = ['id' => $evaluation->klassci_evaluation_id] + ($fenetre === null ? [] : ['programmation' => ['window' => $fenetre]]);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($ligne): void {
            $mock->shouldReceive('requestWithUserToken')
                ->andReturnUsing(static fn (string $jeton, string $endpoint): array => $endpoint === 'me/dashboard'
                    ? ['data' => ['classe' => ['id' => self::CLASSE, 'name' => 'L1']]]
                    : ['data' => [$ligne]]);
        });
    }

    private function demarrer(Evaluation $evaluation): TestResponse
    {
        return $this->asTenant($this->eleve)->postJson("/api/evaluations/{$evaluation->id}/start");
    }
}
