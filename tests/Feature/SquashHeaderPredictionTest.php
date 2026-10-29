<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #946 — teste `scripts/predict-squash-header.php`, qui prédit l'en-tête que
 * GitHub donnera au commit d'une fusion par squash.
 *
 * La CI de `lms` rougissait après coup : commitlint ne lit, sur une PR, que les
 * commits de la PR, jamais l'en-tête que la fusion fabrique (titre du commit ou
 * de la PR, plus « (#N) »). Le script le prédit, et la CI de la PR passe cette
 * prédiction à commitlint, avec la même configuration que sur `lms`.
 *
 * Le script ne porte AUCUNE règle de longueur : les règles restent dans
 * `.commitlintrc.json`. Il ne fait que prédire.
 *
 * @see scripts/predict-squash-header.php
 * @see .github/workflows/squash-header.yml
 */
final class SquashHeaderPredictionTest extends TestCase
{
    /**
     * @param  array<int, string>  $args
     * @return array{0: int, 1: string} [code de sortie, sortie standard]
     */
    private function predire(array $args): array
    {
        $script = base_path('scripts/predict-squash-header.php');
        $ligne = implode(' ', array_map('escapeshellarg', $args));

        $sortie = [];
        $code = 0;
        exec('php '.escapeshellarg($script).' '.$ligne.' 2>&1', $sortie, $code);

        return [$code, implode("\n", $sortie)];
    }

    /** @return iterable<string, array{0: array<int, string>, 1: string}> */
    public static function predictions(): iterable
    {
        yield 'un seul commit : son en-tete' => [
            ['COMMIT_OR_PR_TITLE', '978', '1', 'titre de la PR ignore', 'fix(962): la matiere de mes cours lit son libelle'],
            'fix(962): la matiere de mes cours lit son libelle (#978)',
        ];
        yield 'plusieurs commits : le titre de la PR' => [
            ['COMMIT_OR_PR_TITLE', '981', '2', 'fix(963): l ordre du contenu d une lecon est total', 'en-tete du dernier commit ignore'],
            'fix(963): l ordre du contenu d une lecon est total (#981)',
        ];
        yield 'reglage PR_TITLE, un seul commit : le titre de la PR' => [
            ['PR_TITLE', '978', '1', 'fix(962): titre de la PR', 'fix(962): en-tete du commit'],
            'fix(962): titre de la PR (#978)',
        ];
        yield 'numero a quatre chiffres : le suffixe fait 8 caracteres' => [
            ['COMMIT_OR_PR_TITLE', '1000', '3', 'fix(1000): un titre', ''],
            'fix(1000): un titre (#1000)',
        ];
    }

    /** @param  array<int, string>  $args */
    #[DataProvider('predictions')]
    public function test_predit_l_en_tete_de_squash(array $args, string $attendu): void
    {
        [$code, $sortie] = $this->predire($args);

        self::assertSame(0, $code, $sortie);
        self::assertSame($attendu, $sortie);
    }

    public function test_le_suffixe_compte_dans_la_longueur(): void
    {
        $titre = 'fix(946): '.str_repeat('a', 54);
        self::assertSame(64, mb_strlen($titre));

        [, $sortie] = $this->predire(['COMMIT_OR_PR_TITLE', '982', '2', $titre, '']);

        self::assertSame(71, mb_strlen($sortie), 'Un titre de 64 caractères donne un en-tête de 71.');
    }

    public function test_refuse_un_reglage_inconnu(): void
    {
        [$code, $sortie] = $this->predire(['COMMIT_MESSAGES', '978', '1', 'titre', 'en-tete']);

        self::assertSame(2, $code);
        self::assertStringContainsString('COMMIT_MESSAGES', $sortie);
    }

    public function test_refuse_un_seul_commit_sans_son_en_tete(): void
    {
        [$code] = $this->predire(['COMMIT_OR_PR_TITLE', '978', '1', 'titre', '']);

        self::assertSame(2, $code);
    }

    /** @return iterable<string, array{0: array<int, string>}> */
    public static function appelsInvalides(): iterable
    {
        yield 'aucun argument' => [[]];
        yield 'numero non numerique' => [['COMMIT_OR_PR_TITLE', 'abc', '2', 'titre', '']];
        yield 'aucun commit' => [['COMMIT_OR_PR_TITLE', '978', '0', 'titre', '']];
        yield 'titre vide' => [['COMMIT_OR_PR_TITLE', '978', '2', '', '']];
    }

    /** @param  array<int, string>  $args */
    #[DataProvider('appelsInvalides')]
    public function test_refuse_un_appel_invalide(array $args): void
    {
        [$code] = $this->predire($args);

        self::assertSame(2, $code);
    }
}
