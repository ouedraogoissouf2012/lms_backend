<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Classe;
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
 * #1698 (C1) : le carnet de notes, un ÉTAT par case élève × évaluation, dans le bilan de la classe
 * (`GET /api/lms/classes/{classe}/resultats`, `eleves[].cellules`). Deux évaluations : un QCM
 * (auto-corrigé : sa note est finale dès la remise) et une dissertation (finale à la correction).
 *
 * | élève | QCM                                              | Dissertation                  |
 * |-------|--------------------------------------------------|-------------------------------|
 * | Awa   | remis, 12 → rendu                                | remise non corrigée, puis une 2e tentative refusée → à corriger |
 * | Binta | remise refusée (`verrouille`) → en retard        | corrigée, 15 → rendu          |
 * | Coffi | ouverte, échéance passée (lue verrouillée) → en retard | entraînement seul → manquant |
 * | Dédé  | 8, puis une 2e tentative refusée → rendu, 8      | rien → manquant               |
 *
 * Vraie route, vrai jeton (`asTenant`), forme validée par le contrat.
 */
final class EtatsDuCarnetTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/lms/classes/{classe}/resultats';

    /** L'identité KLASSCI d'une classe : loin des ids locaux, qui ne repartent pas de 1 sous MySQL. */
    public const KLASSCI = 900078;

    private Institution $ecole;

    private Classe $classe;

    /** @var array<string, User> */
    private array $eleves = [];

    public function test_chaque_case_dit_son_etat_et_sa_note(): void
    {
        $this->carnet();

        $reponse = $this->resultats($this->membre($this->ecole, 'coordinateur'))->assertOk();

        self::assertSame([
            'Awa' => [['rendu', 12], ['a_corriger', null]],
            'Binta' => [['en_retard', null], ['rendu', 15]],
            'Coffi' => [['en_retard', null], ['manquant', null]],
            'Dédé' => [['rendu', 8], ['manquant', null]],
        ], $this->cases($reponse));
        self::assertSame(
            array_column((array) $reponse->json('data.evaluations'), 'id'),
            array_column((array) $reponse->json('data.eleves.0.cellules'), 'evaluation_id'),
            'Une case par colonne, dans l\'ordre des colonnes.',
        );
    }

    public function test_une_case_sans_note_finale_ne_compte_dans_aucune_moyenne(): void
    {
        $this->carnet();

        $reponse = $this->resultats($this->membre($this->ecole, 'coordinateur'))->assertOk();

        // Awa : la dissertation à corriger n'entre pas dans la moyenne (jamais un 0 fabriqué).
        $reponse->assertJsonPath('data.eleves.0.moyenne_generale', 12)
            ->assertJsonPath('data.eleves.1.moyenne_generale', 15)
            ->assertJsonPath('data.eleves.2.moyenne_generale', null);
    }

    public function test_l_eleve_lit_les_cases_de_sa_seule_ligne(): void
    {
        $this->carnet();

        $reponse = $this->resultats($this->eleves['Binta'])->assertOk();

        self::assertSame(['Binta' => [['en_retard', null], ['rendu', 15]]], $this->cases($reponse));
    }

    public function test_ecole_klassci_un_eleve_sans_compte_a_ses_cases(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $this->ecole = $ecole;
        $this->classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => self::KLASSCI, 'libelle' => 'BTS 1']);
        $this->roster([['id' => 9101, 'nom' => 'Sans', 'prenom' => 'Compte']]);
        $qcm = $this->evaluation('QCM', 'qcm', ['classe_id' => null, 'klassci_classe_id' => self::KLASSCI]);
        $this->copie($qcm, null, ['klassci_etudiant_id' => 9101, 'status' => 'verrouille', 'note_sur_20' => null]);
        $coordinateur = $this->membre($ecole, 'coordinateur');
        $coordinateur->forceFill(['klassci_token' => 'jeton-coordinateur'])->save();
        $this->disableKlassciMiddleware();

        $reponse = $this->resultats($coordinateur, self::KLASSCI)->assertOk();

        $reponse->assertJsonPath('data.eleves.0.compte_id', null)
            ->assertJsonPath('data.eleves.0.cellules', [['evaluation_id' => $qcm->id, 'etat' => 'en_retard', 'note' => null]]);
    }

    private function carnet(): void
    {
        $this->ecole = $this->ecole();
        $this->classe = $this->classe($this->ecole, '6e A');
        $this->programme($this->classe, $maths = $this->matiere($this->ecole, 'Maths'), null);
        foreach (['Awa', 'Binta', 'Coffi', 'Dédé'] as $nom) {
            $this->eleves[$nom] = $this->membre($this->ecole, 'etudiant');
            $this->eleves[$nom]->forceFill(['name' => $nom])->save();
            $this->inscrire($this->eleves[$nom], $this->classe, 'actif');
        }
        $qcm = $this->evaluation('QCM', 'qcm', ['matiere_id' => $maths->id]);
        $dissertation = $this->evaluation('Dissertation', 'dissertation', ['matiere_id' => $maths->id]);

        $this->copie($qcm, $this->eleves['Awa'], ['note_sur_20' => '12.00']);
        $this->copie($dissertation, $this->eleves['Awa'], ['note_sur_20' => '0.00']);
        // La remise à corriger l'emporte sur la tentative verrouillée : la note viendra.
        $this->copie($dissertation, $this->eleves['Awa'], ['attempt' => 2, 'status' => 'verrouille', 'note_sur_20' => null, 'submitted_at' => null]);
        $this->copie($qcm, $this->eleves['Binta'], ['status' => 'verrouille', 'note_sur_20' => null, 'submitted_at' => null]);
        $this->copie($dissertation, $this->eleves['Binta'], ['status' => 'corrige', 'note_sur_20' => '15.00']);
        $this->copie($qcm, $this->eleves['Coffi'], ['status' => 'en_cours', 'note_sur_20' => null, 'submitted_at' => null, 'ends_at' => now()->subDays(3)]);
        $this->copie($dissertation, $this->eleves['Coffi'], ['note_sur_20' => '20.00', 'feedback' => '[PRACTICE] entraînement']);
        $this->copie($qcm, $this->eleves['Dédé'], ['note_sur_20' => '8.00', 'submitted_at' => now()->subDays(2)]);
        $this->copie($qcm, $this->eleves['Dédé'], ['attempt' => 2, 'status' => 'verrouille', 'note_sur_20' => null, 'submitted_at' => null, 'started_at' => now()->subDay()]);
    }

    /** @param  array<string, mixed>  $surcharges */
    private function evaluation(string $titre, string $type, array $surcharges = []): Evaluation
    {
        $evaluation = Evaluation::factory()->create(array_merge([
            'institution_id' => $this->ecole->id, 'classe_id' => $this->classe->id, 'matiere_id' => null,
            'klassci_classe_id' => null, 'klassci_matiere_id' => null, 'klassci_evaluation_id' => null, 'klassci_enseignant_id' => null,
            'titre' => $titre, 'coefficient' => '1.00', 'is_published' => true, 'show_results' => true,
            'status' => 'terminee', 'date_evaluation' => now()->subDays(10)->addDays(Evaluation::query()->count()), 'duree_minutes' => 60,
        ], $surcharges));
        EvaluationQuestion::factory()->create(array_merge(
            ['evaluation_id' => $evaluation->id, 'type' => $type],
            $type === 'qcm' ? ['options' => ['a', 'b'], 'correct_answers' => ['a']] : [],
        ));

        return $evaluation;
    }

    /** @param  array<string, mixed>  $surcharges */
    private function copie(Evaluation $evaluation, ?User $eleve, array $surcharges): void
    {
        EvaluationSubmission::factory()->create(array_merge([
            'evaluation_id' => $evaluation->id, 'institution_id' => $evaluation->institution_id,
            'student_id' => $eleve?->id, 'klassci_etudiant_id' => $eleve?->klassci_id, 'attempt' => 1,
            'status' => 'soumis', 'feedback' => null, 'submitted_at' => now()->subDays(3), 'ends_at' => null,
        ], $surcharges, ['score' => $surcharges['note_sur_20'] ?? null]));
    }

    /** @param  array<int, array<string, mixed>>  $etudiants */
    private function roster(array $etudiants): void
    {
        $this->app->instance(ClasseRoster::class, new class($etudiants) implements ClasseRoster
        {
            /** @param  array<int, array<string, mixed>>  $etudiants */
            public function __construct(private readonly array $etudiants) {}

            public function etudiants(int $classeId, string $userToken): array
            {
                return $classeId === EtatsDuCarnetTest::KLASSCI && $userToken === 'jeton-coordinateur' ? $this->etudiants : [];
            }
        });
    }

    private function resultats(User $lecteur, ?int $classe = null): TestResponse
    {
        $reponse = $this->asTenant($lecteur)->getJson('/api/lms/classes/'.($classe ?? $this->classe->id).'/resultats');
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');

        return $reponse;
    }

    /** @return array<string, list<array{mixed, mixed}>> l'état et la note de chaque case, par élève */
    private function cases(TestResponse $reponse): array
    {
        $cases = [];
        foreach ((array) $reponse->json('data.eleves') as $ligne) {
            $cases[$ligne['nom']] = array_map(
                static fn (array $case): array => [$case['etat'], $case['note']],
                (array) ($ligne['cellules'] ?? []),
            );
        }

        return $cases;
    }
}
