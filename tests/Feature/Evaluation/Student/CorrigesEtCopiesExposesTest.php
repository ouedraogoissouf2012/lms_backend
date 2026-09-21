<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Les bonnes réponses étaient servies à qui ne doit pas les voir, et refusées à
 * qui y a droit.
 *
 * ## La fuite
 *
 * `StudentEvaluationsListService` chargeait `Evaluation::with('questions',
 * 'submissions')` puis sérialisait le tout par `toArray()`. Ni
 * `EvaluationQuestion` ni `EvaluationSubmission` ne déclarant de `$hidden`, la
 * liste d'évaluations de l'élève — son écran normal — contenait :
 *
 *   - `correct_answers` de chaque question, AVANT qu'il ne compose ;
 *   - les copies de TOUS ses camarades : réponses, `score`, `note_sur_20`.
 *
 * Le service savait pourtant filtrer : il attachait déjà `student_submission`,
 * la seule copie de l'élève. Il n'avait simplement pas besoin des relations
 * brutes qu'il embarquait — `questions` ne lui servait qu'à compter.
 *
 * ## Le refus symétrique
 *
 * Sur sa propre copie, l'élève doit voir la bonne réponse une fois le délai de
 * correction écoulé. Il ne la voyait jamais : Carbon 3 rend un écart SIGNÉ, si
 * bien que `now()->diffInDays($soumisLe)` vaut -30 pour une copie rendue il y a
 * trente jours, et la comparaison `>= 7` ne peut jamais être vraie.
 *
 * ## Pourquoi la garde vit sur le modèle
 *
 * Deux endroits retiraient `correct_answers` à la main — un `unset` ici, une
 * liste blanche là — et tous les autres l'oubliaient. Masquer par défaut
 * renverse la charge : « penser à masquer » devient « penser à montrer », ce
 * qui échoue du bon côté.
 */
final class CorrigesEtCopiesExposesTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSE_KLASSCI = 4242;

    private Institution $ecole;

    private User $eleve;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->ecole = Institution::factory()->create();
        $this->eleve = User::factory()->student()->create([
            'institution_id' => $this->ecole->id,
            'klassci_id' => 111,
            'klassci_token' => 'jeton-eleve',
        ]);
    }

    /**
     * KLASSCI rend la classe de l'élève, et aucune évaluation distante : la
     * liste se construit alors à partir des seules évaluations LMS.
     */
    private function fakeKlassci(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->andReturnUsing(static function (string $token, string $endpoint) {
                    if ($endpoint === 'me/dashboard') {
                        return ['data' => ['classe' => ['id' => self::CLASSE_KLASSCI]]];
                    }

                    return ['data' => []];
                });
        });
    }

    private function evaluationAvecCorrige(): Evaluation
    {
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $this->ecole->id,
            'klassci_classe_id' => self::CLASSE_KLASSCI,
            'is_published' => true,
            'status' => 'en_cours',
            'bareme' => 20,
        ]);
        EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->id,
            'institution_id' => $this->ecole->id,
            'type' => 'qcm',
            'correct_answers' => ['LA-REPONSE-SECRETE'],
            'points' => 20,
        ]);

        return $evaluation;
    }

    public function test_la_liste_de_l_eleve_ne_livre_pas_le_corrige(): void
    {
        // Le cœur de la fuite : l'écran normal de l'élève, avant qu'il compose.
        $evaluation = $this->evaluationAvecCorrige();
        $this->fakeKlassci();
        Sanctum::actingAs($this->eleve);

        $reponse = $this->getJson('/api/evaluations/student');

        $reponse->assertStatus(200);
        self::assertStringNotContainsString(
            'LA-REPONSE-SECRETE',
            $reponse->getContent() ?: '',
            'Le corrige est servi a l eleve avant l epreuve.',
        );
        self::assertStringNotContainsString(
            'correct_answers',
            $reponse->getContent() ?: '',
            'La cle correct_answers figure dans la liste de l eleve.',
        );
    }

    public function test_la_liste_de_l_eleve_ne_livre_pas_les_copies_des_camarades(): void
    {
        $evaluation = $this->evaluationAvecCorrige();
        // Un camarade a composé : sa copie ne regarde personne d'autre que lui
        // et ses enseignants.
        EvaluationSubmission::create([
            'evaluation_id' => $evaluation->id,
            'klassci_etudiant_id' => 999,
            'student_id' => null,
            'attempt' => 1,
            'status' => 'soumis',
            'answers' => ['reponse' => 'COPIE-DU-CAMARADE'],
            'score' => 17,
            'note_sur_20' => 17,
            'institution_id' => $this->ecole->id,
        ]);
        $this->fakeKlassci();
        Sanctum::actingAs($this->eleve);

        $reponse = $this->getJson('/api/evaluations/student');

        $reponse->assertStatus(200);
        self::assertStringNotContainsString(
            'COPIE-DU-CAMARADE',
            $reponse->getContent() ?: '',
            'Les reponses d un camarade sont servies a l eleve.',
        );
        $charge = $reponse->json('data.0');
        self::assertIsArray($charge);
        self::assertArrayNotHasKey(
            'submissions',
            $charge,
            'La liste embarque la relation submissions : toutes les copies de la classe.',
        );
    }

    public function test_la_liste_generale_ne_livre_pas_les_copies_des_autres(): void
    {
        // `GET /evaluations` n'a aucune garde de rôle — son groupe de routes
        // est explicitement « accessible à tous les utilisateurs
        // authentifiés » — et un élève l'atteint par le calendrier
        // (`useCalendarEvents.js:95`). Il y recevait la relation `submissions`
        // en entier : les copies de tout l'établissement.
        //
        // Le compteur, lui, reste dû : il est calculé côté serveur
        // (`EvaluationEnrichmentService:232`) et ne dépend pas de cette
        // relation.
        $evaluation = $this->evaluationAvecCorrige();
        EvaluationSubmission::create([
            'evaluation_id' => $evaluation->id,
            'klassci_etudiant_id' => 999,
            'student_id' => null,
            'attempt' => 1,
            'status' => 'soumis',
            'answers' => ['reponse' => 'COPIE-DU-CAMARADE'],
            'score' => 17,
            'note_sur_20' => 17,
            'institution_id' => $this->ecole->id,
        ]);
        Sanctum::actingAs($this->eleve);

        $reponse = $this->getJson('/api/evaluations');

        $reponse->assertStatus(200);
        self::assertStringNotContainsString(
            'COPIE-DU-CAMARADE',
            $reponse->getContent() ?: '',
            'La liste generale livre les copies des autres eleves.',
        );
        self::assertSame(
            1,
            $reponse->json('data.0.submissions_count'),
            'Le compteur autoritatif a disparu avec la relation : le bouton « Voir les notes » tomberait.',
        );
    }

    public function test_l_ecran_de_passage_de_l_eleve_ne_recoit_pas_le_corrige(): void
    {
        // `GET /evaluations/{id}` sert DEUX publics par le même chemin :
        // l'enseignant qui rédige (`useCreateQuestions.js:76`) et l'élève qui
        // compose (`useTakeEvaluation.js:57`). Sans distinction, le navigateur
        // de l'élève détenait les bonnes réponses PENDANT l'épreuve.
        $evaluation = $this->evaluationAvecCorrige();
        Sanctum::actingAs($this->eleve);

        $reponse = $this->getJson("/api/evaluations/{$evaluation->id}");

        $reponse->assertStatus(200);
        self::assertStringNotContainsString(
            'LA-REPONSE-SECRETE',
            $reponse->getContent() ?: '',
            'L eleve recoit le corrige sur l ecran ou il compose.',
        );
    }

    public function test_l_enseignant_recoit_le_corrige_pour_editer_ses_questions(): void
    {
        // Contraste indispensable, et garde-fou contre une perte de données :
        // l'écran d'édition repeuple le formulaire depuis `q.correct_answers`
        // (`useCreateQuestions.js:95`). Masquer sans exception ferait
        // enregistrer des corrigés VIDES à l'enseignant.
        $evaluation = $this->evaluationAvecCorrige();
        $enseignant = User::factory()->teacher()->create([
            'institution_id' => $this->ecole->id,
            'klassci_id' => 222,
            'klassci_token' => 'jeton-enseignant',
        ]);
        Sanctum::actingAs($enseignant);

        $reponse = $this->getJson("/api/evaluations/{$evaluation->id}");

        $reponse->assertStatus(200);
        self::assertStringContainsString(
            'LA-REPONSE-SECRETE',
            $reponse->getContent() ?: '',
            'L enseignant ne peut plus relire ses propres corriges : editer les effacerait.',
        );
    }

    public function test_l_eleve_voit_le_corrige_de_sa_copie_une_fois_le_delai_ecoule(): void
    {
        // Le refus symétrique. Carbon 3 rend un écart SIGNÉ : la comparaison
        // `now()->diffInDays($soumisLe) >= 7` ne pouvait jamais être vraie, et
        // l'élève n'a donc jamais vu sa correction.
        $evaluation = $this->evaluationAvecCorrige();
        EvaluationSubmission::create([
            'evaluation_id' => $evaluation->id,
            'klassci_etudiant_id' => $this->eleve->klassci_id,
            'student_id' => $this->eleve->id,
            'attempt' => 1,
            'status' => 'corrige',
            'answers' => [],
            'submitted_at' => now()->subDays(30),
            'institution_id' => $this->ecole->id,
        ]);
        Sanctum::actingAs($this->eleve);

        $reponse = $this->getJson("/api/evaluations/{$evaluation->id}/my-submission");

        $reponse->assertStatus(200);
        self::assertStringContainsString(
            'LA-REPONSE-SECRETE',
            $reponse->getContent() ?: '',
            'Trente jours apres, l eleve ne voit toujours pas sa correction.',
        );
    }

    public function test_l_eleve_ne_voit_pas_le_corrige_avant_le_delai(): void
    {
        // Contraste indispensable : sans lui, une correction toujours visible
        // passerait le test precedent sans remplir son office.
        $evaluation = $this->evaluationAvecCorrige();
        EvaluationSubmission::create([
            'evaluation_id' => $evaluation->id,
            'klassci_etudiant_id' => $this->eleve->klassci_id,
            'student_id' => $this->eleve->id,
            'attempt' => 1,
            'status' => 'corrige',
            'answers' => [],
            'submitted_at' => now()->subDay(),
            'institution_id' => $this->ecole->id,
        ]);
        Sanctum::actingAs($this->eleve);

        $reponse = $this->getJson("/api/evaluations/{$evaluation->id}/my-submission");

        $reponse->assertStatus(200);
        self::assertStringNotContainsString(
            'LA-REPONSE-SECRETE',
            $reponse->getContent() ?: '',
            'La correction est visible avant l echeance du delai.',
        );
    }
}
