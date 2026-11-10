<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Garde structurel : une exception attrapée est journalisée par
 * `CaughtExceptionLog`, et décrite par `ExceptionDescription`.
 *
 * Pour chaque fichier de `app/`, ce garde compte trois formes, hors des
 * commentaires : `->getMessage()` sur une exception dont le type n'est pas sûr
 * par contrat ; une exception passée telle quelle dans un tableau
 * (`'exception' => $e`) ; `->getTraceAsString()`. Le compte doit être celui
 * qui est déclaré ci-dessous, et zéro partout ailleurs.
 *
 * - `TYPES_SURS` : des exceptions dont le message est un libellé métier destiné
 *   au client par contrat (`BusinessException`).
 * - `IMPLEMENTATIONS` : les classes qui portent la règle.
 * - `SITES_NOMMES` : des sites gardés, chacun avec son motif.
 *
 * Le garde est strict : tous les sites ont été migrés vers `CaughtExceptionLog`
 * (la liste des sites à migrer, décroissante, est morte avec la dernière PR du
 * lot). Une forme hors de ces listes est un échec.
 *
 * Le dénominateur est publié : « rien à redire » et « rien regardé » ne
 * produisent jamais le même message.
 *
 * @see app/Support/Logging/CaughtExceptionLog.php
 * @see tests/Feature/Architecture/NoRawEnvelopeRelayTest.php  (le patron)
 */
final class NoRawExceptionMessageTest extends TestCase
{
    /** Message client par contrat : `BusinessException.php`, `ImportPreviewFailed`. */
    private const TYPES_SURS = ['BusinessException', 'ImportPreviewFailed'];

    /** Les lieux de la règle, exemptés nommément. */
    private const IMPLEMENTATIONS = [
        'app/Exceptions/UncaughtExceptionReporting.php',
        'app/Support/Logging/CaughtExceptionLog.php',
        'app/Support/Logging/DescribedFailure.php',
        'app/Support/Logging/ExceptionDescription.php',
    ];

    /** Les noms sous lesquels une exception circule dans le code de `app/`. */
    private const VARIABLES = '(?:e|ex|exception|throwable|th|cause|err|previous)';

    /** @var array<string, array{0: int, 1: string}> fichier => [compte, motif] */
    private const SITES_NOMMES = [
        'app/Http/Controllers/API/Evaluation/Student/EvaluationStudentAttemptController.php' => [1, 'comparaison avec le message d\'un service métier ; rien n\'est écrit'],
        'app/Exceptions/KlassciUnavailableException.php' => [1, 'transport KLASSCI : le message d\'une ConnectionException (hôte, URL)'],
        'app/Services/Klassci/Auth/KlassciAuthClient.php' => [1, 'transport KLASSCI : message d\'une ConnectionException'],
        'app/Services/Klassci/Health/HttpKlassciReachability.php' => [1, 'mesure de joignabilité : message d\'une ConnectionException'],
        'app/Services/Klassci/KlassciHttpClient.php' => [1, 'transport KLASSCI : message d\'une ConnectionException'],
        'app/Support/Shell/ShellExecutor.php' => [1, 'sortie d\'un processus externe (Symfony Process)'],
    ];

    public function test_chaque_fichier_a_exactement_le_compte_declare(): void
    {
        $ecarts = [];
        $inspectes = 0;

        foreach ($this->fichiersPhp() as $chemin) {
            if (in_array($chemin, self::IMPLEMENTATIONS, true)) {
                continue;
            }
            $inspectes++;
            $trouve = $this->compte($chemin);
            $attendu = self::SITES_NOMMES[$chemin][0] ?? 0;
            if ($trouve !== $attendu) {
                $ecarts[] = "{$chemin} : {$trouve} trouvé(s), {$attendu} déclaré(s)";
            }
        }

        // Le dépôt compte plus de 700 fichiers : un plancher bas signalerait un
        // parcours cassé, pas un dépôt propre.
        self::assertGreaterThan(500, $inspectes, "{$inspectes} fichier(s) inspecté(s) seulement : le garde ne prouverait rien.");
        self::assertSame([], $ecarts, "{$inspectes} fichier(s) de app/ inspecté(s). Écarts :\n".implode("\n", $ecarts));
    }

    public function test_les_listes_ne_citent_que_des_fichiers_existants(): void
    {
        foreach ([...array_keys(self::SITES_NOMMES), ...self::IMPLEMENTATIONS] as $chemin) {
            self::assertFileExists(base_path($chemin), "Liste périmée : {$chemin}");
        }
    }

    /**
     * Chaque forme reconnue, prouvée sur un extrait : un garde qui ne rougit
     * jamais ne prouve rien.
     *
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function formes(): iterable
    {
        yield 'message, catch large' => ["try {} catch (\\Throwable \$e) {\n    \$log->error('x', ['m' => \$e->getMessage()]);\n}", 1];
        yield 'message, catch sûr par contrat' => ["try {} catch (BusinessException \$e) {\n    return \$e->getMessage();\n}", 0];
        yield 'message du précédent, même sous un catch sûr' => ["try {} catch (BusinessException \$e) {\n    \$m = \$e->getPrevious()->getMessage();\n}", 1];
        yield 'message, appel nullsafe' => ["try {} catch (\\Throwable \$e) {\n    \$m = \$e->getPrevious()?->getMessage();\n}", 1];
        yield 'message par une chaîne de propriétés' => ['$m = $event->exception->getMessage();', 1];
        yield 'message dans une closure, sous un catch sûr' => ["try {} catch (BusinessException \$e) {\n    \$f = function (\\Throwable \$e) { \$m = \$e->getMessage(); };\n}", 1];
        yield 'message, paramètre de fonction' => ["public function failed(Throwable \$exception): void\n{\n    \$m = \$exception->getMessage();\n}", 1];
        yield 'exception dans un tableau' => ["\$log->error('x', ['exception' => \$e]);", 1];
        yield 'exception dans un tableau, fin de ligne' => ["\$c = [\n    'exception' => \$exception\n];", 1];
        yield 'précédent dans un tableau' => ["\$log->error('x', ['p' => \$e->getPrevious()]);", 1];
        yield 'classe seule dans un tableau' => ["\$log->error('x', ['exception' => \$e::class]);", 0];
        yield 'exception convertie en chaîne' => ['$m = (string) $e;', 1];
        yield 'propriété convertie en chaîne' => ['$n = (string) $e->name;', 0];
        yield 'exception interpolée' => ['$m = "échec : $e";', 1];
        yield 'exception passée au journal' => ['Log::error($e);', 1];
        yield 'exception passée à un journal injecté' => ['$this->logger->warning($exception, []);', 1];
        yield 'exception exportée' => ['$m = var_export($e, true);', 1];
        yield 'trace' => ['$t = $e->getTraceAsString();', 1];
        yield 'commentaire' => ["// \$e->getMessage()\n * 'exception' => \$e,", 0];
    }

    #[DataProvider('formes')]
    public function test_le_garde_reconnait_chaque_forme(string $extrait, int $attendu): void
    {
        self::assertSame($attendu, $this->compteLignes(explode("\n", $extrait)));
    }

    /** Le nombre de formes à compter dans un fichier, commentaires exclus. */
    private function compte(string $chemin): int
    {
        return $this->compteLignes(file(base_path($chemin), FILE_IGNORE_NEW_LINES) ?: []);
    }

    /** @param  list<string>  $lignes */
    private function compteLignes(array $lignes): int
    {
        $total = 0;

        foreach ($lignes as $i => $ligne) {
            $code = ltrim($ligne);
            if (str_starts_with($code, '*') || str_starts_with($code, '/*') || str_starts_with($code, '//')) {
                continue;
            }
            $total += preg_match_all('/->getTraceAsString\(\)/', $ligne);
            $total += preg_match_all('/[\'"]\w+[\'"]\s*=>\s*\$'.self::VARIABLES.'(?:\??->getPrevious\(\))?\s*(?:[,\]\)]|$)/', $ligne);
            $total += preg_match_all('/\(string\)\s*\$'.self::VARIABLES.'\b(?!->|::|\[)|"[^"]*\$'.self::VARIABLES.'(?![\w\[]|->|::)[^"]*"/', $ligne);
            $total += preg_match_all('/(?:Log::\w+|->(?:emergency|alert|critical|error|warning|notice|info|debug|log))\(\s*\$'.self::VARIABLES.'\s*[,)]/', $ligne);
            $total += preg_match_all('/\b(?:var_export|print_r|json_encode|serialize)\(\s*\$'.self::VARIABLES.'\s*[,)]/', $ligne);
            if (preg_match_all('/\$(\w+)((?:\??->\w+(?:\(\))?)*?)\??->getMessage\(\)/', $ligne, $m) > 0) {
                foreach ($m[1] as $k => $variable) {
                    // Une chaîne (propriété, précédent) n'a pas de type connu ici.
                    $total += $m[2][$k] === '' && $this->typeSurPour($lignes, $i, $variable) ? 0 : 1;
                }
            }
        }

        return $total;
    }

    /**
     * Le `catch` (ou le paramètre) qui déclare cette variable, en remontant :
     * sûr seulement si tous ses types sont dans `TYPES_SURS`.
     *
     * @param  list<string>  $lignes
     */
    private function typeSurPour(array $lignes, int $depuis, string $variable): bool
    {
        for ($j = $depuis; $j >= max(0, $depuis - 80); $j--) {
            if (preg_match('/catch\s*\(([^)]*?)\s*\$'.$variable.'\s*\)/', $lignes[$j], $m) === 1
                || preg_match('/(?:function(?:\s+&?\w+)?|fn)\s*\([^)]*?([\w\\\\|]+)\s+\$'.$variable.'\b/', $lignes[$j], $m) === 1) {
                $types = array_map(
                    static fn (string $type): string => ltrim(substr(trim($type), (int) strrpos('\\'.trim($type), '\\')), '\\'),
                    explode('|', $m[1]),
                );

                return array_diff($types, self::TYPES_SURS) === [];
            }
        }

        return false;
    }

    /** @return iterable<string> chemins relatifs, séparés par « / » */
    private function fichiersPhp(): iterable
    {
        $racine = base_path('app');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine)) as $fichier) {
            if ($fichier instanceof SplFileInfo && $fichier->isFile() && $fichier->getExtension() === 'php') {
                yield 'app/'.str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1));
            }
        }
    }
}
