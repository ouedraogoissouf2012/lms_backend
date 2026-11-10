<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Garde structurel : le code de production (`app/`) ne dépend de rien de
 * `Tests\`.
 *
 * Deux formes sont refusées :
 * - un import `use Tests\…;` ;
 * - un `@see` ou un `{@see}` vers `\Tests\…` en nom complet. C'est la forme que
 *   Pint convertit en import : un renvoi de docblock vers un test devient alors
 *   une dépendance de `app/` envers `tests/`.
 *
 * Un test se cite en texte, entre backticks, par son chemin :
 * `tests/Feature/…/UnTest.php`. Pint n'y touche pas.
 *
 * Le dénominateur est publié : « rien à redire » et « rien regardé » ne
 * produisent jamais le même message.
 */
final class AppNeDependPasDesTestsTest extends TestCase
{
    public function test_app_n_importe_ni_ne_cite_en_nom_complet_aucune_classe_de_test(): void
    {
        $ecarts = [];
        $inspectes = 0;

        $fichiers = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($fichiers as $fichier) {
            if (! $fichier instanceof \SplFileInfo || $fichier->getExtension() !== 'php') {
                continue;
            }
            $inspectes++;
            foreach (file($fichier->getPathname(), FILE_IGNORE_NEW_LINES) ?: [] as $i => $ligne) {
                if ($this->compteLigne($ligne) > 0) {
                    $ecarts[] = str_replace('\\', '/', substr($fichier->getPathname(), strlen(base_path()) + 1)).':'.($i + 1).' : '.trim($ligne);
                }
            }
        }

        // Le dépôt compte plus de 700 fichiers dans app/ : un plancher bas
        // signalerait un parcours cassé, pas un dépôt propre.
        self::assertGreaterThan(500, $inspectes, "{$inspectes} fichier(s) inspecté(s) seulement : le garde ne prouverait rien.");
        self::assertSame([], $ecarts, "{$inspectes} fichier(s) de app/ inspecté(s). Dépendances envers Tests\\ :\n".implode("\n", $ecarts));
    }

    /**
     * Chaque forme, prouvée sur un extrait : un garde qui ne rougit jamais ne
     * prouve rien.
     *
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function formes(): iterable
    {
        yield 'import' => ['use Tests\\Feature\\Chapter\\UnTest;', 1];
        yield 'import groupé' => ['use Tests\\Feature\\{UnTest, AutreTest};', 1];
        yield '@see en nom complet' => [' * @see \\Tests\\Feature\\UnTest', 1];
        yield '{@see} en nom complet' => [' * liste, et {@see \\Tests\\Unit\\UnTest} épingle', 1];
        yield 'citation en texte, par le chemin' => [' * `tests/Feature/Chapter/UnTest.php`', 0];
        yield 'citation en texte, par le nom' => [' * Verrouillé par `Tests\\Unit\\UnTest`.', 0];
        yield 'espace de noms voisin' => ['use App\\Tests\\Outil;', 0];
    }

    #[DataProvider('formes')]
    public function test_le_garde_reconnait_chaque_forme(string $ligne, int $attendu): void
    {
        self::assertSame($attendu, $this->compteLigne($ligne));
    }

    private function compteLigne(string $ligne): int
    {
        return preg_match('/^\s*use\s+\\\\?Tests\\\\/', $ligne)
            + preg_match('/@see\s+\\\\Tests\\\\/', $ligne);
    }
}
