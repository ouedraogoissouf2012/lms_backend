<?php

declare(strict_types=1);

namespace Tests\Feature\KnowledgeCheck;

use App\Models\KnowledgeCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\JoueUnQuizDeLecon;
use Tests\TestCase;

/**
 * Partie 3 : la bonne réponse d'un quiz de leçon désigne une de ses options.
 *
 * La bonne réponse est l'INDICE d'une option (l'éditeur range `optionIndex`,
 * l'élève remet un indice, C45) et la notation compare en strict. Un indice
 * hors des options, un texte ou un doublon rendait la question impossible à
 * réussir, sans que personne le voie. Ils sont refusés à la création comme à
 * la mise à jour : 422, motif `correct_answer_not_among_options` (le motif des
 * évaluations, D467 : un motif par concept), et rien n'est écrit.
 *
 * - `single` : un entier dans [0, n - 1] ;
 * - `true_false` : un entier dans [0, 1], avec exactement deux options ;
 * - `multiple` : une liste d'entiers distincts, chacun dans les bornes.
 *
 * Vrai jeton, les deux types d'école.
 */
final class BonneReponseDansLesBornesTest extends TestCase
{
    use ActsAsTenantUser;
    use JoueUnQuizDeLecon;
    use RefreshDatabase;

    private const TROIS = ['Bobo-Dioulasso', 'Ouagadougou', 'Koudougou'];

    private const DEUX = ['Vrai', 'Faux'];

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    public static function refus(): iterable
    {
        $cas = [
            'single : un indice égal au nombre d\'options' => self::question('single', self::TROIS, 3),
            'single : un indice négatif' => self::question('single', self::TROIS, -1),
            'single : un texte d\'indice' => self::question('single', self::TROIS, '1'),
            'single : le texte de l\'option' => self::question('single', self::TROIS, 'Ouagadougou'),
            'single : une liste' => self::question('single', self::TROIS, [1]),
            'true_false : trois options' => self::question('true_false', self::TROIS, 0),
            'true_false : l\'indice 2' => self::question('true_false', self::DEUX, 2),
            'multiple : un indice hors des options' => self::question('multiple', self::TROIS, [0, 3]),
            'multiple : un doublon' => self::question('multiple', self::TROIS, [0, 0]),
            'multiple : un entier seul' => self::question('multiple', self::TROIS, 1),
            'multiple : un texte' => self::question('multiple', self::TROIS, ['0']),
        ];

        foreach (self::volets() as $volet => [$mode]) {
            foreach (['création', 'mise à jour'] as $voie) {
                foreach ($cas as $nom => $question) {
                    yield "$volet, $voie : $nom" => [$mode, $voie, $question];
                }
            }
        }
    }

    /** @param  array<string, mixed>  $question */
    #[DataProvider('refus')]
    public function test_une_bonne_reponse_hors_des_options_est_refusee_sans_rien_ecrire(string $volet, string $voie, array $question): void
    {
        $this->ecoleEtQuiz($volet);
        $avant = $this->quiz->fresh()?->questions;

        $refus = $this->ecrire($voie, [$question]);

        $refus->assertStatus(422)->assertJsonPath('reason', 'correct_answer_not_among_options');
        self::assertSame(1, KnowledgeCheck::query()->withoutGlobalScopes()->where('chapter_id', $this->chapitre->id)->count(), 'aucun quiz créé');
        self::assertSame($avant, $this->quiz->fresh()?->questions, 'le quiz n\'est pas modifié');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function voies(): iterable
    {
        foreach (self::volets() as $volet => [$mode]) {
            yield "$volet, création" => [$mode, 'création'];
            yield "$volet, mise à jour" => [$mode, 'mise à jour'];
        }
    }

    #[DataProvider('voies')]
    public function test_chaque_type_valide_est_accepte_et_garde_en_indices(string $volet, string $voie): void
    {
        $this->ecoleEtQuiz($volet);
        $questions = [
            self::question('single', self::TROIS, 2),
            self::question('true_false', self::DEUX, 1),
            self::question('multiple', self::TROIS, [2, 0]),
        ];

        $reponse = $this->ecrire($voie, $questions);

        $reponse->assertSuccessful();
        $id = $voie === 'création' ? $reponse->json('data.id') : $this->quiz->id;
        $gardees = KnowledgeCheck::query()->withoutGlobalScopes()->findOrFail($id)->questions;
        self::assertSame([2, 1, [2, 0]], array_column($gardees ?? [], 'correct_answer'));
    }

    #[DataProvider('voies')]
    public function test_des_options_qui_ne_sont_pas_une_liste_de_textes_sont_refusees(string $volet, string $voie): void
    {
        $this->ecoleEtQuiz($volet);

        $this->ecrire($voie, [self::question('single', ['a' => 'Oui', 'b' => 'Non'], 0)])->assertStatus(422);
        $this->ecrire($voie, [self::question('single', ['Oui', 2], 0)])->assertStatus(422);
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function ecrire(string $voie, array $questions): TestResponse
    {
        return $voie === 'création'
            ? $this->asTenant($this->auteur)->postJson('/api/knowledge-checks', [
                'chapter_id' => $this->chapitre->id, 'title' => 'Quiz de la partie 3', 'questions' => $questions,
            ])
            : $this->asTenant($this->auteur)->putJson("/api/knowledge-checks/{$this->quiz->id}", ['questions' => $questions]);
    }

    /**
     * @param  array<int|string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function question(string $type, array $options, mixed $bonne): array
    {
        return ['question' => "Une question $type", 'type' => $type, 'options' => $options, 'correct_answer' => $bonne];
    }
}
