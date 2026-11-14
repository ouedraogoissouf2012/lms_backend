<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\InstitutionMode;
use App\Exceptions\MissingKlassciTokenException;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Matiere;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Le tableau de bord de l'élève, `GET /proxy/me/dashboard` : même route, autre
 * source (ADR-EI-01), comme celui de l'enseignant (R4).
 *
 * École créée depuis le LMS : la même forme, lue en local, composée des lecteurs
 * existants (ses classes actives, leur programme, l'audience de ses évaluations
 * E2a, ses notes E4a), sans aucun appel à KLASSCI, marquée `meta.source`.
 * École KLASSCI : la charge de KLASSCI relayée telle quelle, inchangée.
 *
 * Élève dont toutes les inscriptions sont inactives (D57) : refus motivé
 * `enrolment_not_active`, le motif de l'inscription (`StudentEnrolmentService`).
 * Élève sans aucune inscription : un tableau vide, état normal (E2a).
 */
final class TableauDeBordEleveLocalTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const URL = '/api/proxy/me/dashboard';

    private const CHEMIN = '/proxy/me/dashboard';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_ecole_du_lms_l_eleve_lit_son_tableau_en_local_sans_klassci(): void
    {
        $this->aucunAppelKlassci();
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');
        // Un jeton périmé ne change rien : la source est celle du MODE de l'école.
        $eleve->forceFill(['klassci_token' => 'jeton-perime'])->save();
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, null);
        $this->inscrire($eleve, $classe, 'actif');
        $ancienne = $this->classe($ecole, '5e B');
        $this->programme($ancienne, $this->matiere($ecole, 'Latin'), null);
        $this->inscrire($eleve, $ancienne, 'inactif');
        $notee = $this->evaluation($classe, $maths, 'Contrôle 1', ['show_results' => true]);
        $cachee = $this->evaluation($classe, $maths, 'Contrôle 2', ['show_results' => false]);
        $this->note($notee, $eleve, '14.00');
        $this->note($cachee, $eleve, '9.00');

        $reponse = $this->asTenant($eleve)->getJson(self::URL)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertJsonPath('success', true)->assertJsonPath('meta', ['source' => 'lms_local']);
        $reponse->assertJsonPath('data.classe', [
            'id' => $classe->id, 'name' => '6e A', 'libelle' => '6e A', 'code' => $classe->code, 'filiere' => null, 'niveau' => null,
        ]);
        $reponse->assertJsonPath('data.cours', [['id' => $maths->id, 'nom' => 'Maths', 'libelle' => 'Maths']]);
        self::assertEqualsCanonicalizing([$notee->id, $cachee->id], array_column((array) $reponse->json('data.quiz'), 'id'));
        // C30 : la note cachée n'apparaît pas, et n'entre pas dans la moyenne.
        $reponse->assertJsonPath('data.notes', [[
            'id' => $notee->id,
            'evaluation' => ['id' => $notee->id, 'titre' => 'Contrôle 1'],
            'matiere' => ['id' => $maths->id, 'name' => 'Maths'],
            'note' => 14.0,
        ]]);
        $reponse->assertJsonPath('data.statistiques', ['moyenne_generale' => 14.0, 'taux_presence' => null]);
    }

    /** D57 : des inscriptions, toutes inactives. Refus motivé, avant toute lecture. */
    public function test_un_eleve_aux_inscriptions_toutes_inactives_est_refuse(): void
    {
        $this->aucunAppelKlassci();
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');
        $this->inscrire($eleve, $this->classe($ecole, '6e A'), 'inactif');

        $reponse = $this->asTenant($eleve)->getJson(self::URL)->assertStatus(409);

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertExactJson([
            'success' => false,
            'message' => 'Votre inscription n\'est pas active. Adressez-vous à votre établissement.',
            'reason' => 'enrolment_not_active',
        ]);
    }

    /** Aucune inscription : un élève pas encore inscrit. Un tableau vide, sans erreur. */
    public function test_un_eleve_sans_inscription_lit_un_tableau_vide(): void
    {
        $this->aucunAppelKlassci();
        $eleve = $this->membre($this->ecole(), 'etudiant');

        $reponse = $this->asTenant($eleve)->getJson(self::URL)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertJsonPath('data', [
            'classe' => null,
            'cours' => [],
            'quiz' => [],
            'notes' => [],
            'statistiques' => ['moyenne_generale' => null, 'taux_presence' => null],
        ]);
    }

    /** L'inscription d'une AUTRE école ne compte pas : bornée à l'établissement de l'élève. */
    public function test_la_classe_d_une_autre_ecole_ne_fait_pas_le_tableau(): void
    {
        $this->aucunAppelKlassci();
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');
        $autreEcole = $this->ecole();
        $ailleurs = $this->classe($autreEcole, 'Ailleurs');
        $this->programme($ailleurs, $this->matiere($autreEcole, 'Grec'), null);
        $this->inscrire($eleve, $ailleurs, 'actif');

        $this->asTenant($eleve)->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.classe', null)
            ->assertJsonPath('data.cours', []);
    }

    public function test_ecole_klassci_la_charge_de_klassci_est_relayee_telle_quelle(): void
    {
        $charge = ['success' => true, 'data' => ['classe' => ['id' => 4, 'name' => 'BTS'], 'cours' => [], 'quiz' => [], 'notes' => []]];
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($charge): void {
            $mock->shouldReceive('requestWithUserToken')->with('jeton-eleve', 'me/dashboard', 'GET')->once()->andReturn($charge);
        });
        $eleve = $this->membre($this->ecole(InstitutionMode::Klassci), 'etudiant');
        $eleve->forceFill(['klassci_token' => 'jeton-eleve'])->save();

        $reponse = $this->asTenant($eleve)->getJson(self::URL)->assertOk()->assertExactJson($charge);

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
    }

    /**
     * Laravel garde le contrôleur sur l'objet Route : une source reçue par le
     * constructeur resterait celle de la première école servie (règle 3).
     */
    public function test_deux_ecoles_dans_la_meme_methode_recoivent_chacune_leur_source(): void
    {
        $this->aucunAppelKlassci();
        $sansJeton = $this->membre($this->ecole(InstitutionMode::Klassci), 'etudiant');
        $sansJeton->forceFill(['klassci_token' => null])->save();
        $local = $this->membre($this->ecole(), 'etudiant');

        $this->asTenant($sansJeton)->getJson(self::URL)
            ->assertUnauthorized()
            ->assertExactJson(MissingKlassciTokenException::payload());
        $this->asTenant($local)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('meta.source', 'lms_local');
    }

    /** @param  array<string, mixed>  $surcharges */
    private function evaluation(Classe $classe, Matiere $matiere, string $titre, array $surcharges = []): Evaluation
    {
        // Comme `LocalEvaluationProgramming` l'écrit : ids locaux, aucune identité KLASSCI.
        $evaluation = Evaluation::factory()->create(array_merge([
            'institution_id' => $classe->institution_id,
            'classe_id' => $classe->getKey(),
            'matiere_id' => $matiere->getKey(),
            'matiere_nom' => $matiere->libelle,
            'klassci_evaluation_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
            'klassci_enseignant_id' => null,
            'titre' => $titre,
            'coefficient' => '1.00',
            'is_published' => true,
            'status' => 'terminee',
            'date_evaluation' => now()->subDay(),
            'duree_minutes' => 60,
        ], $surcharges));
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey(), 'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['a']]);

        return $evaluation;
    }

    private function note(Evaluation $evaluation, User $eleve, string $note): EvaluationSubmission
    {
        return EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->getKey(),
            'institution_id' => $evaluation->institution_id,
            'student_id' => $eleve->getKey(),
            'klassci_etudiant_id' => null,
            'attempt' => 1,
            'status' => 'soumis',
            'feedback' => null,
            'score' => $note,
            'note_sur_20' => $note,
            'submitted_at' => now(),
        ]);
    }

    /** Aucun appel à KLASSCI : ni requête sortante, ni méthode du mandataire. */
    private function aucunAppelKlassci(): void
    {
        Http::preventStrayRequests();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('requestWithUserToken', 'getMatieres');
        });
    }
}
