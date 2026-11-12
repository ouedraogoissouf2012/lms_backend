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
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\Support\OpenApi\OpenApiSchemaValidator;
use Tests\TestCase;

/**
 * D72, E2a : dans une école créée depuis le LMS, l'élève voit les évaluations
 * publiées de SES classes actives.
 *
 * Avant : la liste répondait 401 (`klassci_id` exigé), l'audience lisait la
 * classe chez KLASSCI (`me/dashboard`) et filtrait sur `klassci_classe_id`, la
 * copie n'appartenait à l'élève que par `klassci_etudiant_id`.
 *
 * Vraie route, vrai jeton (`asTenant`), les deux types d'école.
 */
final class EleveVoitSesEvaluationsTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const LISTE = '/api/evaluations/student';

    // ───────────────────────── la liste, école créée depuis le LMS

    public function test_l_eleve_voit_les_evaluations_publiees_de_sa_classe_avec_la_fenetre_locale(): void
    {
        [$ecole, $eleve, $classe, $maths] = $this->eleveInscrit();
        $evaluation = $this->evaluationPubliee($classe, $maths, now()->subHour(), 120);

        $reponse = $this->asTenant($eleve)->getJson(self::LISTE)->assertOk();
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/student', 'get');

        self::assertSame([$evaluation->getKey()], array_column((array) $reponse->json('data'), 'id'));
        $reponse->assertJsonPath('data.0.classe', ['id' => $classe->getKey(), 'nom' => '6e A'])
            ->assertJsonPath('data.0.matiere', ['id' => $maths->getKey(), 'nom' => 'Maths'])
            ->assertJsonPath('data.0.questions_count', 1)
            ->assertJsonPath('data.0.student_submission', null)
            ->assertJsonPath('data.0.programmation.window.has_started', true)
            ->assertJsonPath('data.0.programmation.window.is_open', true)
            ->assertJsonPath('data.0.programmation.window.has_ended', false);
    }

    public function test_l_eleve_ne_voit_ni_brouillon_ni_evaluation_vide_ni_autre_classe_ni_autre_ecole(): void
    {
        [$ecole, $eleve, $classe, $maths] = $this->eleveInscrit();
        $visible = $this->evaluationPubliee($classe, $maths, now()->addDay(), 60);
        // Chaque clause du filtre, prouvée SEULE (revue d'E2a) :
        // non publiée mais d'un statut visible ; publiée mais en brouillon.
        $this->evaluationPubliee($classe, $maths, now()->addDay(), 60, ['is_published' => false]);
        $this->evaluationPubliee($classe, $maths, now()->addDay(), 60, ['status' => 'brouillon']);
        $this->evaluationPubliee($classe, $maths, now()->addDay(), 60, [], questions: 0);
        $this->evaluationPubliee($this->classe($ecole, '5e B'), $maths, now()->addDay(), 60);
        // Une autre école, avec le MÊME `classe_id` que la classe de l'élève :
        // seul le filtre d'établissement l'écarte.
        $ailleurs = $this->ecole();
        $this->evaluationPubliee($classe, $this->matiere($ailleurs, 'Maths'), now()->addDay(), 60, ['institution_id' => $ailleurs->getKey()]);

        $ids = array_column((array) $this->asTenant($eleve)->getJson(self::LISTE)->assertOk()->json('data'), 'id');

        self::assertSame([$visible->getKey()], $ids);
    }

    public function test_l_eleve_inscrit_dans_deux_classes_voit_les_evaluations_des_deux(): void
    {
        // Q1 (D152) : l'audience couvre toutes les classes actives, et la clé
        // `classe` est celle de l'évaluation.
        [$ecole, $eleve, $classe, $maths] = $this->eleveInscrit();
        $seconde = $this->classe($ecole, 'Atelier');
        $this->inscrire($eleve, $seconde, 'actif');
        $a = $this->evaluationPubliee($classe, $maths, now()->addDay(), 60);
        $b = $this->evaluationPubliee($seconde, $maths, now()->addDays(2), 60);

        $data = (array) $this->asTenant($eleve)->getJson(self::LISTE)->assertOk()->json('data');

        self::assertEqualsCanonicalizing([$a->getKey(), $b->getKey()], array_column($data, 'id'));
        $parId = array_column($data, 'classe', 'id');
        self::assertSame('6e A', $parId[$a->getKey()]['nom']);
        self::assertSame('Atelier', $parId[$b->getKey()]['nom']);
    }

    public function test_une_inscription_non_active_ne_voit_rien(): void
    {
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');
        $classe = $this->classe($ecole, '6e A');
        $this->inscrire($eleve, $classe, 'inactif');
        $this->evaluationPubliee($classe, $this->matiere($ecole, 'Maths'), now()->addDay(), 60);

        $this->asTenant($eleve)->getJson(self::LISTE)->assertOk()->assertExactJson(['success' => true, 'data' => []]);
    }

    public function test_la_fenetre_locale_suit_les_dates_de_l_evaluation(): void
    {
        // Q2 (D132) : ouverture à `date_evaluation`, fermeture à `deadline_at`,
        // sinon à `date_evaluation + duree_minutes` ; fermée si la date est nulle.
        [$ecole, $eleve, $classe, $maths] = $this->eleveInscrit();
        $avenir = $this->evaluationPubliee($classe, $maths, now()->addDay(), 60);
        $passee = $this->evaluationPubliee($classe, $maths, now()->subHours(3), 60);
        $prolongee = $this->evaluationPubliee($classe, $maths, now()->subHours(3), 60, ['deadline_at' => now()->addHour()]);
        $sansDate = $this->evaluationPubliee($classe, $maths, null, 60);

        $fenetres = array_column((array) $this->asTenant($eleve)->getJson(self::LISTE)->assertOk()->json('data'), 'programmation', 'id');

        self::assertSame([false, false, false], $this->etat($fenetres[$avenir->getKey()]));
        self::assertSame([true, false, true], $this->etat($fenetres[$passee->getKey()]));
        self::assertSame([true, true, false], $this->etat($fenetres[$prolongee->getKey()]));
        self::assertSame([false, false, false], $this->etat($fenetres[$sansDate->getKey()]));
    }

    public function test_un_jeton_klassci_perime_ne_fait_partir_aucun_appel(): void
    {
        [$ecole, $eleve, $classe, $maths] = $this->eleveInscrit();
        $eleve->forceFill(['klassci_token' => 'jeton-perime'])->save();
        $this->evaluationPubliee($classe, $maths, now()->addDay(), 60);
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('requestWithUserToken');
        });
        Http::preventStrayRequests();

        $this->asTenant($eleve)->getJson(self::LISTE)->assertOk()->assertJsonCount(1, 'data');
    }

    // ───────────────────────── le détail et « ma copie »

    public function test_l_eleve_ouvre_le_detail_d_une_evaluation_de_sa_classe_et_pas_celle_d_une_autre(): void
    {
        [$ecole, $eleve, $classe, $maths] = $this->eleveInscrit();
        $sienne = $this->evaluationPubliee($classe, $maths, now()->addDay(), 60);
        $autre = $this->evaluationPubliee($this->classe($ecole, '5e B'), $maths, now()->addDay(), 60);

        $this->asTenant($eleve)->getJson('/api/evaluations/'.$sienne->getKey())->assertOk()->assertJsonPath('data.id', $sienne->getKey());
        $this->asTenant($eleve)->getJson('/api/evaluations/'.$autre->getKey())->assertNotFound();
    }

    public function test_l_eleve_lit_sa_copie_et_pas_celle_d_un_camarade(): void
    {
        [$ecole, $eleve, $classe, $maths] = $this->eleveInscrit();
        $evaluation = $this->evaluationPubliee($classe, $maths, now()->subHour(), 120);
        $camarade = $this->membre($ecole, 'etudiant');
        $this->inscrire($camarade, $classe, 'actif');
        $this->copie($evaluation, $camarade);

        $this->asTenant($eleve)->getJson($this->copieDe($evaluation))->assertNotFound();

        $sienne = $this->copie($evaluation, $eleve);
        $this->asTenant($eleve)->getJson($this->copieDe($evaluation))->assertOk()->assertJsonPath('data.id', $sienne->getKey());
    }

    // ───────────────────────── l'école créée depuis KLASSCI (déclaré)

    public function test_dans_une_ecole_klassci_la_liste_exige_toujours_l_identite_klassci(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $eleve = $this->membre($ecole, 'etudiant');

        $this->asTenant($eleve)->getJson(self::LISTE)
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'klassci_token_missing');
    }

    public function test_dans_une_ecole_klassci_un_eleve_sans_klassci_id_lit_sa_copie_par_student_id(): void
    {
        // Déclaré (E2a) : la copie appartient à son auteur par `student_id`.
        // « Ma copie » répondait 401 à un élève sans `klassci_id`.
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $eleve = $this->membre($ecole, 'etudiant');
        $evaluation = Evaluation::factory()->create(['institution_id' => $ecole->getKey(), 'is_published' => true, 'status' => 'planifiee']);
        $copie = $this->copie($evaluation, $eleve);

        $this->asTenant($eleve)->getJson($this->copieDe($evaluation))->assertOk()->assertJsonPath('data.id', $copie->getKey());
    }

    public function test_dans_une_ecole_klassci_une_copie_d_avant_reste_a_son_auteur_par_klassci_etudiant_id(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $eleve = $this->membre($ecole, 'etudiant');
        $eleve->forceFill(['klassci_id' => 501, 'klassci_token' => 'jeton'])->save();
        $evaluation = Evaluation::factory()->create(['institution_id' => $ecole->getKey(), 'is_published' => true, 'status' => 'planifiee']);
        $copie = $this->copie($evaluation, $eleve, ['student_id' => null, 'klassci_etudiant_id' => 501]);

        $this->disableKlassciMiddleware();
        $this->asTenant($eleve)->getJson($this->copieDe($evaluation))->assertOk()->assertJsonPath('data.id', $copie->getKey());
    }

    public function test_le_schema_des_elements_mord(): void
    {
        // Sans cette preuve, un schéma trop lâche passerait tout en silence.
        [$ecole, $eleve, $classe, $maths] = $this->eleveInscrit();
        $this->evaluationPubliee($classe, $maths, now()->addDay(), 60);
        $reponse = $this->asTenant($eleve)->getJson(self::LISTE)->assertOk();
        $spec = OpenApiSchemaValidator::depuisFichier(base_path('docs/openapi.yaml'));
        $schema = $spec->schemaDeReponse('/evaluations/student', 'get', 200);
        self::assertNotNull($schema);

        $sansCompte = json_decode((string) $reponse->getContent(), false, 512, JSON_THROW_ON_ERROR);
        unset($sansCompte->data[0]->questions_count);
        self::assertNotSame([], $spec->erreurs($sansCompte, $schema), 'le schéma admet un élément sans questions_count');

        $fenetreFausse = json_decode((string) $reponse->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $fenetreFausse->data[0]->programmation->window->is_open = 'oui';
        self::assertNotSame([], $spec->erreurs($fenetreFausse, $schema), 'le schéma admet une fenêtre au booléen faux');
    }

    public function test_dans_une_ecole_klassci_la_liste_relayee_suit_la_spec(): void
    {
        // La réponse de l'école créée depuis KLASSCI, avec ce que KLASSCI
        // relaie réellement : des ids en chaîne, et un objet vide (`{}`) qui
        // ressort en tableau vide après décodage.
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $eleve = $this->membre($ecole, 'etudiant');
        $eleve->forceFill(['klassci_id' => 501, 'klassci_token' => 'jeton'])->save();
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $ecole->getKey(), 'klassci_classe_id' => 77, 'klassci_evaluation_id' => 900,
            'is_published' => true, 'status' => 'planifiee',
        ]);
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey()]);
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->with('jeton', 'me/dashboard', 'GET')
                ->andReturn(['data' => ['classe' => ['id' => 77, 'nom' => 'Terminale D']]]);
            $mock->shouldReceive('requestWithUserToken')->with('jeton', 'evaluations', 'GET')
                ->andReturn(['data' => [[
                    'id' => 900,
                    'programmation' => ['date_evaluation' => '2026-11-20', 'window' => [
                        'is_open' => false, 'has_started' => false, 'has_ended' => false,
                        'start_at' => '2026-11-20T08:00:00+00:00', 'end_at' => '2026-11-20T10:00:00+00:00',
                    ]],
                    'lms_integration' => [],
                    'classe' => ['id' => '77', 'nom' => 'Terminale D'],
                    'matiere' => ['id' => '10', 'nom' => 'Maths'],
                ]]]);
        });
        $this->disableKlassciMiddleware();

        $reponse = $this->asTenant($eleve)->getJson(self::LISTE)->assertOk()->assertJsonPath('data.0.id', $evaluation->getKey());

        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/student', 'get');
    }

    // ───────────────────────── harnais

    /**
     * @return array{Institution, User, Classe, Matiere}
     */
    private function eleveInscrit(): array
    {
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');
        $classe = $this->classe($ecole, '6e A');
        $this->inscrire($eleve, $classe, 'actif');

        return [$ecole, $eleve, $classe, $this->matiere($ecole, 'Maths')];
    }

    /**
     * @param  array<string, mixed>  $surcharges
     */
    private function evaluationPubliee(Classe $classe, Matiere $matiere, mixed $date, int $duree, array $surcharges = [], int $questions = 1): Evaluation
    {
        $evaluation = Evaluation::factory()->create(array_merge([
            'institution_id' => $classe->institution_id,
            'classe_id' => $classe->getKey(),
            'matiere_id' => $matiere->getKey(),
            'classe_nom' => $classe->libelle,
            'matiere_nom' => $matiere->libelle,
            'klassci_evaluation_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
            'klassci_enseignant_id' => null,
            'is_published' => true,
            'status' => 'planifiee',
            'date_evaluation' => $date,
            'duree_minutes' => $duree,
            'deadline_at' => null,
        ], $surcharges));
        if ($questions > 0) {
            EvaluationQuestion::factory()->count($questions)->create(['evaluation_id' => $evaluation->getKey()]);
        }

        return $evaluation;
    }

    /**
     * @param  array<string, mixed>  $surcharges
     */
    private function copie(Evaluation $evaluation, User $eleve, array $surcharges = []): EvaluationSubmission
    {
        return EvaluationSubmission::factory()->create(array_merge([
            'evaluation_id' => $evaluation->getKey(),
            'institution_id' => $evaluation->institution_id,
            'student_id' => $eleve->getKey(),
            'klassci_etudiant_id' => null,
            'status' => 'soumis',
        ], $surcharges));
    }

    private function copieDe(Evaluation $evaluation): string
    {
        return '/api/evaluations/'.$evaluation->getKey().'/my-submission';
    }

    /**
     * [has_started, is_open, has_ended]
     *
     * @param  mixed  $programmation
     * @return list<mixed>
     */
    private function etat(mixed $programmation): array
    {
        $fenetre = is_array($programmation) ? ($programmation['window'] ?? []) : [];

        return [$fenetre['has_started'] ?? null, $fenetre['is_open'] ?? null, $fenetre['has_ended'] ?? null];
    }
}
