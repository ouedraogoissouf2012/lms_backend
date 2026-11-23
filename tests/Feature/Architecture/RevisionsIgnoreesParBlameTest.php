<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * #1264 — `.git-blame-ignore-revs` : les commits de pure mise en forme que `git blame` saute,
 * pour rendre chaque ligne à son vrai auteur (d'abord #1263, le passage de Pint sur tout le
 * dépôt).
 *
 * La CI clone sans historique : ce test ne peut pas lancer `git blame`. Il garde la FORME du
 * fichier, que git lit sans rien dire quand elle est fausse : chaque ligne utile est un sha
 * COMPLET (40 caractères hexadécimaux ; un sha court n'est pas reconnu par GitHub), chacun est
 * précédé d'un commentaire qui dit pourquoi, aucun ne revient deux fois, et CONTRIBUTING dit
 * comment l'activer en local. L'effet de blame se mesure à la main (corps de la PR #1264).
 */
final class RevisionsIgnoreesParBlameTest extends TestCase
{
    private const FICHIER = '.git-blame-ignore-revs';

    /** Le passage de Pint sur tout le dépôt (#1263). */
    private const REFORMATAGE_PINT = 'd4dfd42d6a675838cdfdacef006860eb5d81682c';

    public function test_le_fichier_saute_le_reformatage_pint_de_1263(): void
    {
        self::assertContains(self::REFORMATAGE_PINT, $this->shas());
    }

    public function test_chaque_ligne_utile_est_un_sha_complet_unique_et_commente(): void
    {
        $lignes = $this->lignes();
        $shas = [];

        foreach ($lignes as $i => $ligne) {
            if ($ligne === '' || str_starts_with($ligne, '#')) {
                continue;
            }

            self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $ligne, 'Ligne '.($i + 1).' : un sha complet en minuscules, seul.');
            self::assertStringStartsWith('#', $lignes[$i - 1] ?? '', 'Ligne '.($i + 1).' : un commentaire dit pourquoi ce commit est sauté.');
            $shas[] = $ligne;
        }

        self::assertNotSame([], $shas, 'Aucun sha lu : le test ne prouverait rien.');
        self::assertSame($shas, array_values(array_unique($shas)), 'Un sha revient deux fois.');
    }

    public function test_contributing_dit_comment_l_activer(): void
    {
        $contributing = (string) file_get_contents(base_path('CONTRIBUTING.md'));

        self::assertStringContainsString('git config blame.ignoreRevsFile '.self::FICHIER, $contributing);
    }

    /** @return list<string> */
    private function shas(): array
    {
        return array_values(array_filter(
            $this->lignes(),
            static fn (string $ligne): bool => $ligne !== '' && ! str_starts_with($ligne, '#'),
        ));
    }

    /** @return list<string> */
    private function lignes(): array
    {
        $chemin = base_path(self::FICHIER);
        self::assertFileExists($chemin);

        return array_map('trim', explode("\n", (string) file_get_contents($chemin)));
    }
}
