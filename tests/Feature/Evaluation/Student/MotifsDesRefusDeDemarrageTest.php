<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation\Student;

use App\Enums\InstitutionMode;
use App\Exceptions\KlassciUnavailableException;
use App\Exceptions\MissingKlassciTokenException;
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
use Tests\Support\OpenApi\OpenApiSchemaValidator;
use Tests\TestCase;

/**
 * Chaque refus de démarrer une évaluation porte un motif stable, que le
 * client traduit (`reason`, #906). Le statut et le message ne changent pas.
 *
 * | Refus | `reason` |
 * |---|---|
 * | fenêtre fermée | `evaluation_window_closed` |
 * | nombre maximum de tentatives atteint | `evaluation_max_attempts` |
 * | reprise interdite | `evaluation_retake_forbidden` |
 * | évaluation qui ne se passe pas en ligne | `evaluation_offline_only` |
 * | fenêtre non vérifiable chez KLASSCI (503, avec `Retry-After`) | `evaluation_window_unverifiable` |
 *
 * Ces refus n'existent que dans l'école créée depuis KLASSCI : dans l'école
 * créée depuis le LMS, le démarrage s'arrête avant, faute de jeton KLASSCI
 * (dernier test). Vraie route, vrai jeton porteur, états réels en base, KLASSCI
 * simulé (`me/dashboard`, `evaluations`). Chaque 403 suit `docs/openapi.yaml`,
 * dont le schéma est fermé : les quatre motifs, le refus d'un compte sans
 * établissement (`no_institution`), et les refus sans motif.
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

    /** Un compte sans établissement est refusé avant tout, avec son propre motif, que la spec admet. */
    public function test_un_compte_sans_etablissement_recoit_le_motif_no_institution(): void
    {
        $evaluation = $this->evaluation([]);
        $sansEcole = User::factory()->student()->create(['institution_id' => null, 'klassci_id' => 6020, 'klassci_token' => 'jeton']);

        $reponse = $this->asTenant($sansEcole)->postJson("/api/evaluations/{$evaluation->id}/start")->assertForbidden();

        $reponse->assertJsonPath('reason', 'no_institution');
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    /** Un établissement désactivé est refusé sans motif, et la spec l'admet. */
    public function test_un_etablissement_desactive_recoit_un_refus_sans_motif(): void
    {
        $evaluation = $this->evaluation([]);
        $this->ecole->update(['is_active' => false]);

        $reponse = $this->demarrer($evaluation)->assertForbidden()->assertJsonMissingPath('reason');
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    /** L'enum de `reason` mord : un motif hors de la liste sort de la spec. */
    public function test_un_motif_hors_de_la_liste_sort_de_la_spec(): void
    {
        $evaluation = $this->evaluation(['is_online' => false]);
        $this->klassciRend($evaluation, null);
        $corps = json_decode((string) $this->demarrer($evaluation)->assertForbidden()->getContent(), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $corps);
        $corps->reason = 'motif_inconnu';

        $spec = OpenApiSchemaValidator::depuisFichier(base_path('docs/openapi.yaml'));
        $schema = $spec->schemaDeReponse(self::CHEMIN, 'post', 403);
        self::assertNotNull($schema);

        self::assertNotSame([], $spec->erreurs($corps, $schema));
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

        // Le 401 du jeton KLASSCI absent, et non celui d'une session morte. Sa
        // forme est prouvée exacte, plus étroitement que par la spec, qui le
        // décrit par une réponse partagée (`$ref` vers `KlassciUnauthorized`).
        $this->demarrer($evaluation)->assertStatus(401)->assertExactJson(MissingKlassciTokenException::payload());
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

    /**
     * La fenêtre ne peut pas être vérifiée (KLASSCI en panne sur `evaluations`) :
     * 503 avec son motif, et `Retry-After`, la même valeur que toute
     * indisponibilité KLASSCI du dépôt. Aucune tentative n'est créée.
     */
    public function test_une_fenetre_non_verifiable_porte_son_motif_et_retry_after(): void
    {
        config(['services.klassci.retry_after' => 47]);
        $evaluation = $this->evaluation([]);
        $this->klassciEnPanne(surLaClasse: false);

        $reponse = $this->demarrer($evaluation)->assertStatus(503)->assertExactJson([
            'success' => false,
            'message' => "Impossible de vérifier la disponibilité de l'évaluation pour le moment. Veuillez réessayer dans un instant.",
            'reason' => 'evaluation_window_unverifiable',
        ]);

        $reponse->assertHeader('Retry-After', '47');
        self::assertSame(0, EvaluationSubmission::query()->where('evaluation_id', $evaluation->id)->count());
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        // Le schéma fermé du 503 mord : privé de `message`, ou augmenté d'une clé non décrite, il refuse.
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'message');
    }

    /** La classe de l'élève illisible chez KLASSCI : le 503 canonique, avec son motif, suit la même spec. */
    public function test_une_classe_illisible_rend_le_503_canonique(): void
    {
        config(['services.klassci.retry_after' => 47]);
        $evaluation = $this->evaluation([]);
        $this->klassciEnPanne(surLaClasse: true);

        $reponse = $this->demarrer($evaluation)->assertStatus(503)->assertExactJson([
            'success' => false,
            'message' => KlassciUnavailableException::CLIENT_MESSAGE,
            'reason' => KlassciUnavailableException::REASON,
        ]);

        $reponse->assertHeader('Retry-After', '47');
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    /** KLASSCI lève sur la fenêtre (`evaluations`), ou dès la classe de l'élève (`me/dashboard`). */
    private function klassciEnPanne(bool $surLaClasse): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($surLaClasse): void {
            $mock->shouldReceive('requestWithUserToken')
                ->andReturnUsing(static function (string $jeton, string $endpoint) use ($surLaClasse): array {
                    if ($endpoint === 'me/dashboard' && ! $surLaClasse) {
                        return ['data' => ['classe' => ['id' => self::CLASSE, 'name' => 'L1']]];
                    }

                    throw new KlassciUnavailableException('KLASSCI indisponible', 503);
                });
        });
    }

    private function demarrer(Evaluation $evaluation): TestResponse
    {
        return $this->asTenant($this->eleve)->postJson("/api/evaluations/{$evaluation->id}/start");
    }
}
