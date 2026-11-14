<?php

declare(strict_types=1);

namespace Tests\Unit\Services\KnowledgeCheck;

use App\Services\KnowledgeCheck\KnowledgeCheckPresentation;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * C44 : la présentation tirée au démarrage, et la traduction de la remise.
 * Graine fixe ; chaque cas vérifie que la permutation n'est pas l'identité.
 */
final class KnowledgeCheckPresentationTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $questions;

    private KnowledgeCheckPresentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presentation = new KnowledgeCheckPresentation(new Randomizer(new Xoshiro256StarStar(44)));
        $this->questions = [];
        for ($i = 0; $i < 5; $i++) {
            $this->questions[] = ['question' => "Q$i", 'type' => 'multiple', 'options' => ["Q$i-a", "Q$i-b", "Q$i-c", "Q$i-d"], 'correct_answer' => [0, 2]];
        }
    }

    public function test_le_tirage_est_une_permutation_de_chaque_niveau(): void
    {
        $tirage = $this->presentation->tirer($this->questions, true, true);

        self::assertNotSame([0, 1, 2, 3, 4], $tirage['questions']);
        self::assertEqualsCanonicalizing([0, 1, 2, 3, 4], $tirage['questions']);
        foreach ($tirage['options'] as $ordre) {
            self::assertEqualsCanonicalizing([0, 1, 2, 3], $ordre);
        }
    }

    public function test_sans_melange_le_tirage_est_l_identite(): void
    {
        $tirage = $this->presentation->tirer($this->questions, false, false);

        self::assertSame([0, 1, 2, 3, 4], $tirage['questions']);
        self::assertSame(array_fill(0, 5, [0, 1, 2, 3]), $tirage['options']);
    }

    public function test_la_remise_traduite_vers_l_origine_designe_les_options_vues(): void
    {
        $tirage = $this->presentation->tirer($this->questions, true, true);
        $affichees = $this->presentation->presenter($this->questions, $tirage);

        // L'élève coche, à chaque position affichée, les options vues en 1re et 3e place.
        $origine = $this->presentation->versOrigine($tirage, array_fill(0, 5, [0, 2]));

        self::assertNotNull($origine);
        foreach ($affichees as $p => $question) {
            $q = $tirage['questions'][$p];
            $vues = [$question['options'][0], $question['options'][2]];
            $traduites = array_map(fn (int $o): string => $this->questions[$q]['options'][$o], $origine[$q]);
            self::assertSame($vues, $traduites);
        }
    }

    public function test_un_indice_hors_des_options_ou_une_reponse_de_trop_ne_se_traduit_pas(): void
    {
        $tirage = $this->presentation->tirer($this->questions, true, true);

        self::assertNull($this->presentation->versOrigine($tirage, [4]));
        self::assertNull($this->presentation->versOrigine($tirage, [[0, 4]]));
        self::assertNull($this->presentation->versOrigine($tirage, array_fill(0, 6, null)));
        self::assertSame([], array_filter((array) $this->presentation->versOrigine($tirage, [null, []]), static fn ($r): bool => $r !== null && $r !== []));
    }

    public function test_la_concordance_avec_le_quiz_courant(): void
    {
        $tirage = $this->presentation->tirer($this->questions, true, true);
        self::assertTrue($this->presentation->concorde($this->questions, $tirage));

        $uneOptionDeMoins = $this->questions;
        array_pop($uneOptionDeMoins[3]['options']);
        self::assertFalse($this->presentation->concorde($uneOptionDeMoins, $tirage));

        self::assertFalse($this->presentation->concorde(array_slice($this->questions, 0, 4), $tirage));

        // D520 : à nombre égal, l'empreinte du contenu voit l'ordre et les textes.
        $reordonnees = $this->questions;
        [$reordonnees[0], $reordonnees[1]] = [$reordonnees[1], $reordonnees[0]];
        self::assertFalse($this->presentation->concorde($reordonnees, $tirage));

        $renommee = $this->questions;
        $renommee[2]['options'][0] = 'Une autre option';
        self::assertFalse($this->presentation->concorde($renommee, $tirage));

        // La bonne réponse n'est pas présentée : la corriger après le démarrage ne bloque pas la remise.
        $corrigee = $this->questions;
        $corrigee[4]['correct_answer'] = [1];
        self::assertTrue($this->presentation->concorde($corrigee, $tirage));
    }

    public function test_le_detail_revient_dans_l_ordre_affiche(): void
    {
        $tirage = $this->presentation->tirer($this->questions, true, true);
        $affichees = $this->presentation->presenter($this->questions, $tirage);
        $detail = [];
        foreach ($this->questions as $q => $question) {
            $detail[] = ['question_index' => $q, 'user_answer' => null, 'correct_answer' => $question['correct_answer'], 'is_correct' => false, 'explanation' => null];
        }

        $rendu = $this->presentation->versAffiche($tirage, $detail, array_fill(0, 5, [1]));

        foreach ($rendu as $p => $ligne) {
            self::assertSame($p, $ligne['question_index']);
            self::assertSame([1], $ligne['user_answer']);
            $q = $tirage['questions'][$p];
            $bonnes = array_map(fn (int $o): string => $affichees[$p]['options'][$o], $ligne['correct_answer']);
            self::assertEqualsCanonicalizing([$this->questions[$q]['options'][0], $this->questions[$q]['options'][2]], $bonnes);
        }
    }
}
