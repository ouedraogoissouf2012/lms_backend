<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * Les options d'une question sont une liste de textes, ou `null`.
 *
 * Avant : `questions.*.options` n'était validé qu'en `nullable|array`, sans règle sur ses
 * éléments. Le serveur gardait une liste d'entiers, d'objets ou de listes, et même un
 * tableau associatif, que l'aperçu rendait en OBJET JSON. Le front n'envoie que des
 * chaînes (`useCreateQuestions.js`, `QuestionEditorCard.vue`) : le serveur l'impose
 * désormais, en création comme en mise à jour, et la spec peut le dire.
 *
 * Chaque corps est valide en tout point sauf ses options : sans cela, un 422 ou un 403
 * venu d'ailleurs (programmation, identité KLASSCI) passerait pour celui-ci. Les deux
 * types d'école. Vrai jeton (`asTenant`).
 */
final class OptionsListeDeTextesTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    /** Les champs de programmation valides de l'école en cours. @var array<string, int> */
    private array $programmation = [];

    /** @return array<string, array{InstitutionMode}> */
    public static function ecoles(): array
    {
        return [
            'école du LMS' => [InstitutionMode::Standalone],
            'école KLASSCI' => [InstitutionMode::Klassci],
        ];
    }

    /** @return array<string, array{InstitutionMode, mixed, string}> */
    public static function optionsRefusees(): array
    {
        $cas = [
            'des nombres' => [[3, 4], 'questions.0.options.0'],
            'des objets' => [[['libelle' => '3'], ['libelle' => '4']], 'questions.0.options.0'],
            'des listes' => [[['3'], ['4']], 'questions.0.options.0'],
            'un tableau associatif' => [['a' => '3', 'b' => '4'], 'questions.0.options'],
            'un texte' => ['3, 4', 'questions.0.options'],
        ];
        $jeux = [];
        foreach (self::ecoles() as $ecole => [$mode]) {
            foreach ($cas as $nom => [$options, $cle]) {
                $jeux["$ecole, $nom"] = [$mode, $options, $cle];
            }
        }

        return $jeux;
    }

    #[DataProvider('optionsRefusees')]
    public function test_la_creation_refuse_des_options_qui_ne_sont_pas_une_liste_de_textes(InstitutionMode $mode, mixed $options, string $cle): void
    {
        $auteur = $this->auteurDans($mode);

        $this->asTenant($auteur)
            ->postJson('/api/evaluations', $this->creation([$this->question($options)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$cle]);

        self::assertSame(0, DB::table('evaluations')->count());
    }

    #[DataProvider('optionsRefusees')]
    public function test_la_mise_a_jour_refuse_des_options_qui_ne_sont_pas_une_liste_de_textes(InstitutionMode $mode, mixed $options, string $cle): void
    {
        $auteur = $this->auteurDans($mode);
        $evaluation = $this->evaluationDe($auteur);

        $this->asTenant($auteur)
            ->putJson('/api/evaluations/'.$evaluation->id, ['questions' => [$this->question($options)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$cle]);

        self::assertSame([['3', '4']], $this->optionsDe($evaluation));
    }

    /**
     * Une chaîne JSON n'est jamais décodée : elle est refusée, pour `options` comme pour
     * `correct_answers`. Les services n'ont donc pas à la décoder (B6, revue de Fille 1).
     */
    #[DataProvider('ecoles')]
    public function test_une_chaine_json_est_refusee_pour_options_et_correct_answers(InstitutionMode $mode): void
    {
        $auteur = $this->auteurDans($mode);
        $question = ['question' => 'Combien font 2 + 2 ?', 'type' => 'qcm', 'points' => 2, 'options' => '["3","4"]', 'correct_answers' => '["4"]'];

        $this->asTenant($auteur)->postJson('/api/evaluations', $this->creation([$question]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['questions.0.options', 'questions.0.correct_answers']);
        self::assertSame(0, DB::table('evaluations')->count());

        $evaluation = $this->evaluationDe($auteur);
        $this->asTenant($auteur)->putJson('/api/evaluations/'.$evaluation->id, ['questions' => [$question]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['questions.0.options', 'questions.0.correct_answers']);
        self::assertSame([['3', '4']], $this->optionsDe($evaluation));
    }

    /** Le témoin : une liste de textes, et `null` pour une question sans choix, passent. */
    #[DataProvider('ecoles')]
    public function test_une_liste_de_textes_et_null_sont_acceptes_en_creation_et_en_mise_a_jour(InstitutionMode $mode): void
    {
        $auteur = $this->auteurDans($mode);

        $creee = $this->asTenant($auteur)
            ->postJson('/api/evaluations', $this->creation([$this->question(['3', '4'])]))
            ->assertCreated();
        $evaluation = Evaluation::withoutGlobalScopes()->findOrFail($creee->json('data.id'));
        self::assertSame([['3', '4']], $this->optionsDe($evaluation));

        $this->asTenant($auteur)->putJson('/api/evaluations/'.$evaluation->id, ['questions' => [
            ['question' => 'Vrai ?', 'type' => 'vrai_faux', 'points' => 1, 'options' => ['Vrai', 'Faux']],
            ['question' => 'Expliquez.', 'type' => 'dissertation', 'points' => 5, 'options' => null],
        ]])->assertOk();
        self::assertSame([['Vrai', 'Faux'], null], $this->optionsDe($evaluation));
    }

    /** Un auteur à qui l'école ouvre la création : programmé localement, ou enseignant KLASSCI. */
    private function auteurDans(InstitutionMode $mode): User
    {
        $ecole = $this->ecole($mode);
        $auteur = $this->membre($ecole, 'enseignant');

        if ($mode === InstitutionMode::Standalone) {
            $classe = $this->classe($ecole, '6e A');
            $maths = $this->matiere($ecole, 'Maths');
            $this->programme($classe, $maths, $auteur);
            $this->programmation = ['classe_id' => $classe->id, 'matiere_id' => $maths->id];

            return $auteur;
        }

        // La resynchronisation du profil (#90) n'est pas l'objet de ce test.
        $this->disableKlassciMiddleware();
        $auteur->forceFill(['klassci_enseignant_id' => 42, 'klassci_token' => 'jeton-enseignant'])->save();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getMatieres')->andReturn(['data' => [['id' => 10, 'nom' => 'Mathématiques']]]);
            $mock->shouldReceive('getClasses')->andReturn(['data' => [['id' => 20, 'libelle' => 'Terminale D']]]);
        });
        $this->programmation = ['klassci_evaluation_id' => 900, 'klassci_classe_id' => 20, 'klassci_matiere_id' => 10];

        return $auteur;
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @return array<string, mixed>
     */
    private function creation(array $questions): array
    {
        return [
            ...$this->programmation,
            'titre' => 'Contrôle 1',
            'type' => 'qcm',
            'date_evaluation' => now()->addDay()->toIso8601String(),
            'duree_minutes' => 30,
            'questions' => $questions,
        ];
    }

    /** @return array<string, mixed> */
    private function question(mixed $options): array
    {
        return [
            'question' => 'Combien font 2 + 2 ?',
            'type' => 'qcm',
            'points' => 2,
            'options' => $options,
            'correct_answers' => ['4'],
        ];
    }

    private function evaluationDe(User $auteur): Evaluation
    {
        $evaluation = Evaluation::factory()->brouillon()->create([
            'institution_id' => $auteur->institution_id,
            'enseignant_id' => $auteur->id,
        ]);
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->id, 'options' => ['3', '4']]);

        return $evaluation;
    }

    /** @return list<mixed> */
    private function optionsDe(Evaluation $evaluation): array
    {
        return EvaluationQuestion::query()->where('evaluation_id', $evaluation->id)->orderBy('id')
            ->get()->map(fn (EvaluationQuestion $question): mixed => $question->options)->all();
    }
}
