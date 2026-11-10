<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Garde structurel : le code chargé en production (`app/`, `bootstrap/`,
 * `config/`, `database/`, `routes/`) ne dépend de rien de `Tests\`.
 *
 * Deux formes sont refusées :
 * - une instruction `use` qui nomme `Tests\…`, quelle qu'en soit la casse (PHP
 *   résout les espaces de noms sans la casse), groupée ou non, `use function`
 *   compris ;
 * - tout nom complet `\Tests\…`, dans le code (`new`, `::class`) comme dans un
 *   docblock (`@see`, `@var`, `@param`, `@return`, `@throws`, `@property`,
 *   `@mixin`). Pint convertit ces formes en import : un renvoi vers un test
 *   deviendrait une dépendance du code de production envers `tests/`.
 *
 * Un test se cite en texte, entre backticks, par son chemin :
 * `tests/Feature/…/UnTest.php`. Pint n'y touche pas.
 *
 * Le dénominateur est publié : « rien à redire » et « rien regardé » ne
 * produisent jamais le même message.
 */
final class AppNeDependPasDesTestsTest extends TestCase
{
    /** Le code chargé en production : `database/` porte les factories et les seeders autoloadés. */
    private const DOSSIERS = ['app', 'bootstrap', 'config', 'database', 'routes'];

    /** Une instruction `use` qui nomme `Tests\`, hors d'un autre espace de noms (`App\Tests\…`). */
    private const IMPORT = '/^\s*use\s[^;]*(?<![\w\\\\])Tests\\\\/i';

    /** Un nom complet `\Tests\`, hors d'un autre espace de noms. */
    private const NOM_COMPLET = '/(?<![\w\\\\])\\\\Tests\\\\/i';

    public function test_le_code_de_production_ne_nomme_aucune_classe_de_test(): void
    {
        $ecarts = [];
        $inspectes = 0;

        foreach (self::DOSSIERS as $dossier) {
            $fichiers = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dossier), \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($fichiers as $fichier) {
                if (! $fichier instanceof \SplFileInfo || $fichier->getExtension() !== 'php') {
                    continue;
                }
                $lignes = file($fichier->getPathname(), FILE_IGNORE_NEW_LINES);
                self::assertIsArray($lignes, "Fichier illisible : {$fichier->getPathname()}");
                $inspectes++;
                $ecarts = [...$ecarts, ...$this->ecartsDe($fichier->getPathname(), $lignes)];
            }
        }

        // Le dépôt compte plus de 700 fichiers chargés en production : un
        // plancher bas signalerait un parcours cassé, pas un dépôt propre.
        self::assertGreaterThan(500, $inspectes, "{$inspectes} fichier(s) inspecté(s) seulement : le garde ne prouverait rien.");
        self::assertSame(
            [],
            $ecarts,
            "{$inspectes} fichier(s) inspecté(s) (".implode(', ', self::DOSSIERS)."). Dépendances envers Tests\\ :\n".implode("\n", $ecarts),
        );
    }

    /**
     * Chaque forme, prouvée sur un extrait : un garde qui ne rougit jamais ne
     * prouve rien.
     *
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function formes(): iterable
    {
        yield 'import' => ['use Tests\Feature\Chapter\UnTest;', 1];
        yield 'import groupé' => ['use Tests\Feature\{UnTest, AutreTest};', 1];
        yield 'import parmi d\'autres' => ['use App\Foo, Tests\Bar;', 1];
        yield 'import de fonction' => ['use function Tests\outil;', 1];
        yield 'import en minuscules' => ['use tests\Feature\UnTest;', 1];
        yield '@see en nom complet' => [' * @see \Tests\Feature\UnTest', 1];
        yield '{@see} en nom complet' => [' * liste, et {@see \Tests\Unit\UnTest} épingle', 1];
        yield '@param en nom complet' => [' * @param  \Tests\Feature\Fixture  $f', 1];
        yield '@var en nom complet' => [' /** @var \Tests\Feature\Fixture $f */', 1];
        yield '::class en nom complet' => ['$c = \Tests\Feature\UnTest::class;', 1];
        yield 'new en nom complet' => ['$f = new \Tests\Support\Fixture();', 1];
        yield 'citation en texte, par le chemin' => [' * `tests/Feature/Chapter/UnTest.php`', 0];
        yield 'citation en texte, par le nom' => [' * Verrouillé par `Tests\Unit\UnTest`.', 0];
        yield 'espace de noms voisin, importé' => ['use App\Tests\Outil;', 0];
        yield 'espace de noms voisin, en nom complet' => ['$o = new \App\Tests\Outil();', 0];
    }

    #[DataProvider('formes')]
    public function test_le_garde_reconnait_chaque_forme(string $ligne, int $attendu): void
    {
        self::assertSame($attendu, $this->compteLigne($ligne));
    }

    /**
     * @param  list<string>  $lignes
     * @return list<string>
     */
    private function ecartsDe(string $chemin, array $lignes): array
    {
        $ecarts = [];
        foreach ($lignes as $i => $ligne) {
            if ($this->compteLigne($ligne) > 0) {
                $ecarts[] = str_replace('\\', '/', substr($chemin, strlen(base_path()) + 1)).':'.($i + 1).' : '.trim($ligne);
            }
        }

        return $ecarts;
    }

    private function compteLigne(string $ligne): int
    {
        return preg_match(self::IMPORT, $ligne) === 1 ? 1 : (preg_match(self::NOM_COMPLET, $ligne) === 1 ? 1 : 0);
    }
}
