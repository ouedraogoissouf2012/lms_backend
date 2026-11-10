<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Garde structurel : le code chargé en production (`app/`, `bootstrap/` hors
 * de son cache généré, `config/`, `database/`, `routes/`) ne nomme aucune
 * classe de `Tests\`.
 *
 * La lecture se fait par jetons PHP (`token_get_all`), pas par lignes : un nom
 * se résout selon son espace de noms, et une chaîne n'est pas un nom.
 *
 * Est compté :
 * - un nom complet `\Tests\…` (`new`, `::class`, type, attribut…) ;
 * - un nom qualifié `Tests\…` dans un fichier SANS espace de noms
 *   (`config/`, `routes/`, migrations), où PHP le résout en `\Tests\…` ;
 * - un nom qui suit un `use` de premier niveau (import simple, groupé, parmi
 *   d'autres, `use function`), quelle qu'en soit la casse ;
 * - dans un commentaire ou un docblock, tout nom complet `\Tests\…` (`@see`,
 *   `@var`, `@param`, `@return`, `@throws`, `@property`, `@mixin`) : Pint les
 *   convertit en import.
 *
 * N'est pas compté : une chaîne, une citation en texte (`tests/…/UnTest.php`
 * ou `` `Tests\Unit\UnTest` `` sans antislash initial), un espace de noms
 * voisin (`App\Tests\…`).
 *
 * Le dénominateur est publié : « rien à redire » et « rien regardé » ne
 * produisent jamais le même message.
 */
final class AppNeDependPasDesTestsTest extends TestCase
{
    /** Le code chargé en production : `database/` porte les factories, les seeders et les migrations. */
    private const DOSSIERS = ['app', 'bootstrap', 'config', 'database', 'routes'];

    /** Fichiers générés et ignorés par git : ils reflètent le poste, pas le dépôt. */
    private const EXCLUS = ['bootstrap/cache/'];

    /** Dans un commentaire : un nom complet `\Tests\`, hors d'un autre espace de noms. */
    private const NOM_COMPLET_EN_COMMENTAIRE = '/(?<![\w\\\\])\\\\Tests\\\\/i';

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
                $chemin = str_replace('\\', '/', substr($fichier->getPathname(), strlen(base_path()) + 1));
                if ($this->estExclu($chemin)) {
                    continue;
                }
                $source = file_get_contents($fichier->getPathname());
                self::assertIsString($source, "Fichier illisible : {$chemin}");
                $inspectes++;
                foreach ($this->lignesFautives($source) as $ligne) {
                    $ecarts[] = "{$chemin}:{$ligne}";
                }
            }
        }

        // Le dépôt compte plus de 800 fichiers chargés en production : un
        // plancher bas signalerait un parcours cassé, pas un dépôt propre.
        self::assertGreaterThan(500, $inspectes, "{$inspectes} fichier(s) inspecté(s) seulement : le garde ne prouverait rien.");
        self::assertSame(
            [],
            $ecarts,
            "{$inspectes} fichier(s) inspecté(s) (".implode(', ', self::DOSSIERS).', hors '.implode(', ', self::EXCLUS)."). Noms de Tests\\ :\n".implode("\n", $ecarts),
        );
    }

    /**
     * Chaque forme, prouvée sur un fichier entier : un garde qui ne rougit
     * jamais ne prouve rien.
     *
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function formes(): iterable
    {
        $dansApp = "<?php\nnamespace App\\Services;\n";
        $global = "<?php\n";

        yield 'import' => [$dansApp.'use Tests\Feature\Chapter\UnTest;', 1];
        yield 'import groupé' => [$dansApp.'use Tests\Feature\{UnTest, AutreTest};', 1];
        yield 'import parmi d\'autres' => [$dansApp.'use App\Foo, Tests\Bar;', 1];
        yield 'import de fonction' => [$dansApp.'use function Tests\outil;', 1];
        yield 'import en minuscules' => [$dansApp.'use tests\Feature\UnTest;', 1];
        yield '@see en nom complet' => [$dansApp."/**\n * @see \\Tests\\Feature\\UnTest\n */\nclass A {}", 1];
        yield '{@see} en nom complet' => [$dansApp."/** liste, et {@see \\Tests\\Unit\\UnTest} épingle */\nclass A {}", 1];
        yield '@param en nom complet' => [$dansApp."/** @param  \\Tests\\Feature\\Fixture  \$f */\nfunction f(\$f) {}", 1];
        yield '@return en nom complet' => [$dansApp."/** @return \\Tests\\Feature\\Fixture */\nfunction f() {}", 1];
        yield 'nom complet entre backticks, dans un docblock' => [$dansApp."/** Voir `\\Tests\\Unit\\UnTest`. */\nclass A {}", 1];
        yield '::class en nom complet' => [$dansApp.'$c = \Tests\Feature\UnTest::class;', 1];
        yield 'new en nom complet' => [$dansApp.'$f = new \Tests\Support\Fixture();', 1];
        yield 'nom qualifié dans un fichier global (config)' => [$global."return ['driver' => Tests\\Fakes\\Driver::class];", 1];
        yield 'nom qualifié dans un fichier global (route)' => [$global."Route::get('/x', [Tests\\Fake\\C::class, 'i']);", 1];
        yield 'citation en texte, par le chemin' => [$dansApp."/** `tests/Feature/Chapter/UnTest.php` */\nclass A {}", 0];
        yield 'citation en texte, par le nom' => [$dansApp."/** Verrouillé par `Tests\\Unit\\UnTest`. */\nclass A {}", 0];
        yield 'nom qualifié dans un espace de noms (relatif)' => [$dansApp.'$o = new Tests\Outil();', 0];
        yield 'espace de noms voisin, importé' => [$dansApp.'use App\Tests\Outil;', 0];
        yield 'espace de noms voisin, en nom complet' => [$dansApp.'$o = new \App\Tests\Outil();', 0];
        yield 'chemin Windows dans une chaîne' => [$dansApp.'$x = \'..\tests\fixtures\';', 0];
        yield 'nom dans une chaîne' => [$dansApp.'$x = \'\Tests\Foo\';', 0];
        yield 'use de closure' => [$dansApp.'$f = function () use ($tests) { return $tests; };', 0];
    }

    #[DataProvider('formes')]
    public function test_le_garde_reconnait_chaque_forme(string $source, int $attendu): void
    {
        self::assertCount($attendu, $this->lignesFautives($source));
    }

    private function estExclu(string $chemin): bool
    {
        foreach (self::EXCLUS as $prefixe) {
            if (str_starts_with($chemin, $prefixe)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les numéros de ligne où un nom de `Tests\` apparaît.
     *
     * @return list<int>
     */
    private function lignesFautives(string $source): array
    {
        $jetons = token_get_all($source);
        $global = ! in_array(T_NAMESPACE, array_map(static fn (mixed $j): mixed => is_array($j) ? $j[0] : $j, $jetons), true);
        $lignes = [];
        $profondeur = 0;
        $dansUnUse = false;

        foreach ($jetons as $jeton) {
            if (! is_array($jeton)) {
                $profondeur += match ($jeton) { '{' => 1, '}' => -1, default => 0 };
                $dansUnUse = $dansUnUse && $jeton !== ';';

                continue;
            }
            [$type, $texte, $ligne] = $jeton;
            if ($type === T_USE) {
                $dansUnUse = $profondeur === 0;
            }
            if ($this->nommeUnTest($type, $texte, $global, $dansUnUse)) {
                $lignes[] = $ligne;
            }
        }

        return array_values(array_unique($lignes));
    }

    private function nommeUnTest(int $type, string $texte, bool $global, bool $dansUnUse): bool
    {
        return match ($type) {
            T_NAME_FULLY_QUALIFIED => stripos($texte, '\\Tests\\') === 0,
            T_NAME_QUALIFIED => stripos($texte, 'Tests\\') === 0 && ($global || $dansUnUse),
            T_DOC_COMMENT, T_COMMENT => preg_match(self::NOM_COMPLET_EN_COMMENTAIRE, $texte) === 1,
            default => false,
        };
    }
}
