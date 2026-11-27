<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\DispenseDEvaluation;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\Classe\ClasseRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1698 (C2) : `PUT` et `DELETE /api/evaluations/{id}/dispenses`. Un élève dispensé n'a plus de
 * note qui compte pour l'évaluation : ni dans le bilan (moyenne, rang), ni dans « Mes notes » ;
 * sa case du carnet se lit `dispense`, et l'évaluation n'est pas une note manquante.
 *
 * | élève | Contrôle 1 | Contrôle 2 | moyenne | dispensée du contrôle 1 |
 * |-------|------------|------------|---------|-------------------------|
 * | Awa   | 8          | 16         | 12      | 16, rang 1              |
 * | Binta | 14         | 10         | 12      | —                       |
 * | Coffi | —          | 11         | 11      | (dispensé aussi : aucune note manquante) |
 *
 * Vraie route, vrai jeton (`asTenant`), forme validée par le contrat.
 */
final class DispenseDEvaluationTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/evaluations/{id}/dispenses';

    /** L'identité KLASSCI d'une classe : loin des ids locaux, qui ne repartent pas de 1 sous MySQL. */
    public const KLASSCI = 900079;

    private Institution $ecole;

    private Classe $classe;

    private User $proprietaire;

    private Evaluation $controle1;

    /** @var array<string, User> */
    private array $eleves = [];

    public function test_la_note_dispensee_sort_du_bilan_et_de_mes_notes_puis_y_revient(): void
    {
        $this->jeu();
        $coordinateur = $this->membre($this->ecole, 'coordinateur');

        $this->dispenser($coordinateur, ['compte_id' => $this->eleves['Awa']->id, 'motif' => 'Certificat médical'])
            ->assertOk()->assertJsonPath('data.dispense', true)->assertJsonPath('data.motif', 'Certificat médical');
        $this->dispenser($coordinateur, ['compte_id' => $this->eleves['Coffi']->id])->assertOk();

        $bilan = $this->bilan($coordinateur);
        self::assertSame([['Awa', 16, 1], ['Binta', 12, 2], ['Coffi', 11, 3]], $this->classement($bilan));
        $bilan->assertJsonPath('data.eleves.0.cellules.0', ['evaluation_id' => $this->controle1->id, 'etat' => 'dispense', 'note' => null])
            ->assertJsonPath('data.eleves.0.notes_manquantes', [])
            ->assertJsonPath('data.eleves.2.cellules.0.etat', 'dispense')
            ->assertJsonPath('data.eleves.2.notes_manquantes', []);
        $this->mesNotes($this->eleves['Awa'])->assertJsonPath('data.moyenne_generale', 16);

        $this->lever($coordinateur, $this->eleves['Awa']->id)->assertOk()->assertJsonPath('data.dispense', false);

        self::assertSame(['Awa', 12, 1], $this->classement($this->bilan($coordinateur))[0]);
        $this->mesNotes($this->eleves['Awa'])->assertJsonPath('data.moyenne_generale', 12);
    }

    public function test_accorder_et_lever_sont_idempotents(): void
    {
        $this->jeu();
        $admin = $this->membre($this->ecole, 'superAdmin');

        $this->dispenser($admin, ['compte_id' => $this->eleves['Binta']->id, 'motif' => 'Premier'])->assertOk();
        $this->dispenser($admin, ['compte_id' => $this->eleves['Binta']->id, 'motif' => 'Second'])->assertOk();

        self::assertSame(['Second'], DispenseDEvaluation::query()->withoutGlobalScope('institution')->pluck('motif')->all());
        $this->lever($admin, $this->eleves['Binta']->id)->assertOk();
        $this->lever($admin, $this->eleves['Binta']->id)->assertOk()->assertJsonPath('data.dispense', false);
        self::assertSame(0, DispenseDEvaluation::query()->withoutGlobalScope('institution')->count());
    }

    /**
     * QUI écrit une dispense, figé : la règle de lecture des notes de l'évaluation
     * (`ChecksEvaluationOwnership::checkEvaluationReadAccess`). Un élargissement futur de cette
     * règle changerait ce tableau, et se verrait ici.
     */
    public function test_qui_peut_ecrire_une_dispense(): void
    {
        $this->jeu();
        $auteurs = [
            'admin de l école' => $this->membre($this->ecole, 'superAdmin'),
            'coordinateur de l école' => $this->membre($this->ecole, 'coordinateur'),
            'enseignant propriétaire' => $this->proprietaire,
            'autre enseignant' => $this->membre($this->ecole, 'enseignant'),
            'élève de la classe' => $this->eleves['Binta'],
            'coordinateur d une autre école' => $this->membre($this->ecole(), 'coordinateur'),
        ];

        $statuts = [];
        foreach ($auteurs as $qui => $auteur) {
            $statuts[$qui] = [
                $this->dispenser($auteur, ['compte_id' => $this->eleves['Awa']->id])->status(),
                $this->lever($auteur, $this->eleves['Awa']->id)->status(),
            ];
        }

        self::assertSame([
            'admin de l école' => [200, 200],
            'coordinateur de l école' => [200, 200],
            'enseignant propriétaire' => [200, 200],
            'autre enseignant' => [403, 403],
            'élève de la classe' => [403, 403],
            'coordinateur d une autre école' => [403, 403],
        ], $statuts);
        self::assertSame(0, DispenseDEvaluation::query()->withoutGlobalScope('institution')->count());
    }

    public function test_l_eleve_doit_etre_designe_et_de_la_classe(): void
    {
        $this->jeu();
        $coordinateur = $this->membre($this->ecole, 'coordinateur');
        $horsClasse = $this->membre($this->ecole, 'etudiant');

        $this->dispenser($coordinateur, ['compte_id' => $horsClasse->id])->assertUnprocessable()->assertJsonPath('reason', 'student_not_in_class');
        $this->dispenser($coordinateur, ['motif' => 'Sans élève'])->assertUnprocessable();
        self::assertSame(0, DispenseDEvaluation::query()->withoutGlobalScope('institution')->count());
    }

    public function test_ecole_klassci_un_eleve_du_roster_sans_compte_est_dispense_par_son_identite(): void
    {
        $this->ecole = $this->ecole(InstitutionMode::Klassci);
        $this->classe = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => self::KLASSCI, 'libelle' => 'BTS 1']);
        $this->app->instance(ClasseRoster::class, new class implements ClasseRoster
        {
            public function etudiants(int $classeId, string $userToken): array
            {
                return $classeId === DispenseDEvaluationTest::KLASSCI && $userToken === 'jeton-coordinateur'
                    ? [['id' => 9201, 'nom' => 'Sans', 'prenom' => 'Compte']] : [];
            }
        });
        $this->controle1 = $this->evaluation('Devoir KLASSCI', ['classe_id' => null, 'klassci_classe_id' => self::KLASSCI]);
        $this->copie($this->controle1, null, '6.00', 9201);
        $coordinateur = $this->membre($this->ecole, 'coordinateur');
        $coordinateur->forceFill(['klassci_token' => 'jeton-coordinateur'])->save();
        $this->disableKlassciMiddleware();

        $this->dispenser($coordinateur, ['klassci_etudiant_id' => 9201])->assertOk()
            ->assertJsonPath('data.compte_id', null)->assertJsonPath('data.klassci_etudiant_id', 9201);

        $this->bilan($coordinateur, self::KLASSCI)
            ->assertJsonPath('data.eleves.0.cellules.0.etat', 'dispense')
            ->assertJsonPath('data.eleves.0.moyenne_generale', null);
    }

    private function jeu(): void
    {
        $this->ecole = $this->ecole();
        $this->classe = $this->classe($this->ecole, '6e A');
        $this->programme($this->classe, $maths = $this->matiere($this->ecole, 'Maths'), null);
        $this->proprietaire = $this->membre($this->ecole, 'enseignant');
        foreach (['Awa', 'Binta', 'Coffi'] as $nom) {
            $this->eleves[$nom] = $this->membre($this->ecole, 'etudiant');
            $this->eleves[$nom]->forceFill(['name' => $nom])->save();
            $this->inscrire($this->eleves[$nom], $this->classe, 'actif');
        }
        $this->controle1 = $this->evaluation('Contrôle 1', ['matiere_id' => $maths->id]);
        $controle2 = $this->evaluation('Contrôle 2', ['matiere_id' => $maths->id]);
        foreach (['Awa' => ['8.00', '16.00'], 'Binta' => ['14.00', '10.00'], 'Coffi' => [null, '11.00']] as $nom => [$premier, $second]) {
            if ($premier !== null) {
                $this->copie($this->controle1, $this->eleves[$nom], $premier);
            }
            $this->copie($controle2, $this->eleves[$nom], $second);
        }
    }

    /** @param  array<string, mixed>  $surcharges */
    private function evaluation(string $titre, array $surcharges = []): Evaluation
    {
        $evaluation = Evaluation::factory()->create(array_merge([
            'institution_id' => $this->ecole->id, 'classe_id' => $this->classe->id, 'matiere_id' => null,
            'enseignant_id' => isset($this->proprietaire) ? $this->proprietaire->id : null,
            'klassci_classe_id' => null, 'klassci_matiere_id' => null, 'klassci_evaluation_id' => null, 'klassci_enseignant_id' => null,
            'titre' => $titre, 'coefficient' => '1.00', 'is_published' => true, 'show_results' => true,
            'status' => 'terminee', 'date_evaluation' => now()->subDays(10)->addDays(Evaluation::query()->count()), 'duree_minutes' => 60,
        ], $surcharges));
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->id, 'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['a']]);

        return $evaluation;
    }

    private function copie(Evaluation $evaluation, ?User $eleve, string $note, ?int $identiteKlassci = null): void
    {
        EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->id, 'institution_id' => $evaluation->institution_id,
            'student_id' => $eleve?->id, 'klassci_etudiant_id' => $identiteKlassci ?? $eleve?->klassci_id, 'attempt' => 1,
            'status' => 'soumis', 'feedback' => null, 'score' => $note, 'note_sur_20' => $note, 'submitted_at' => now()->subDay(),
        ]);
    }

    /** @param  array<string, mixed>  $corps */
    private function dispenser(User $auteur, array $corps): TestResponse
    {
        $reponse = $this->asTenant($auteur)->putJson('/api/evaluations/'.$this->controle1->id.'/dispenses', $corps);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'put');

        return $reponse;
    }

    private function lever(User $auteur, int $compte): TestResponse
    {
        $reponse = $this->asTenant($auteur)->deleteJson('/api/evaluations/'.$this->controle1->id.'/dispenses?compte_id='.$compte);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'delete');

        return $reponse;
    }

    private function bilan(User $lecteur, ?int $classe = null): TestResponse
    {
        $reponse = $this->asTenant($lecteur)->getJson('/api/lms/classes/'.($classe ?? $this->classe->id).'/resultats');
        $this->assertMatchesOpenApiResponse($reponse, '/lms/classes/{classe}/resultats', 'get');

        return $reponse->assertOk();
    }

    private function mesNotes(User $eleve): TestResponse
    {
        $reponse = $this->asTenant($eleve)->getJson('/api/my-grades');
        $this->assertMatchesOpenApiResponse($reponse, '/my-grades', 'get');

        return $reponse->assertOk();
    }

    /** @return list<array{mixed, mixed, mixed}> */
    private function classement(TestResponse $reponse): array
    {
        return array_values(array_map(
            static fn (array $l): array => [$l['nom'], $l['moyenne_generale'], $l['rang']],
            (array) $reponse->json('data.eleves'),
        ));
    }
}
