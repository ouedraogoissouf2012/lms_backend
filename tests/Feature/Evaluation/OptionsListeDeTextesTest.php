<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\EcritDesQuestions;
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
    use EcritDesQuestions;
    use RefreshDatabase;


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
            // Les bornes (B5) : 26 options au plus, 1 000 caractères au plus par option.
            '27 options' => [array_fill(0, 27, 'x'), 'questions.0.options'],
            'une option de 1 001 caractères' => [[str_repeat('a', 1001)], 'questions.0.options.0'],
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

    /** Le témoin des bornes : 26 options de 1 000 caractères passent, en création et en mise à jour (B5). */
    #[DataProvider('ecoles')]
    public function test_vingt_six_options_de_mille_caracteres_sont_acceptees(InstitutionMode $mode): void
    {
        $auteur = $this->auteurDans($mode);
        $options = array_map(static fn (int $i): string => str_pad((string) $i, 1000, 'o'), range(1, 26));

        $creee = $this->asTenant($auteur)
            ->postJson('/api/evaluations', $this->creation([$this->question($options)]))
            ->assertCreated();
        $evaluation = Evaluation::withoutGlobalScopes()->findOrFail($creee->json('data.id'));
        self::assertSame([$options], $this->optionsDe($evaluation));

        $this->asTenant($auteur)->putJson('/api/evaluations/'.$evaluation->id, ['questions' => [$this->question($options)]])
            ->assertOk();
        self::assertSame([$options], $this->optionsDe($evaluation));
    }
}
