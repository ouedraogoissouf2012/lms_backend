<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Garde structurelle de #886 (ADR-886-01) : la table `classes` n'a que ses deux
 * écrivains.
 *
 * - `ClasseSyncService` écrit le miroir d'une classe KLASSCI, pour l'enseignant
 *   comme pour l'élève : le chemin de l'élève passe par le job existant
 *   `SyncUserClasses`, jamais par un second écrivain (ADR-878-01, cause racine
 *   de #673).
 * - `LocalClasseCreator` crée la classe d'une école autonome.
 *
 * ## Ce qui est refusé ailleurs
 *
 * Une création ou une écriture de masse sur le modèle `Classe` (`create`,
 * `updateOrCreate`, `firstOrCreate`, `upsert`, `insert`), et un `insert` ou un
 * `upsert` sur la table `classes` par le constructeur de requêtes.
 */
final class ClasseMiroirEcrivainUniqueTest extends TestCase
{
    private const ECRIVAINS = [
        'app/Services/ClasseSyncService.php',
        'app/Services/Catalogue/LocalClasseCreator.php',
    ];

    private const ECRITURES = [
        '/\bClasse::(query\(\)->)?(create|updateOrCreate|firstOrCreate|upsert|insert)\(/',
        '/table\(\s*[\'"]classes[\'"]\s*\)\s*->\s*(insert|upsert|insertOrIgnore)\b/',
    ];

    public function test_la_table_classes_n_a_que_ses_deux_ecrivains(): void
    {
        $inspectes = 0;
        $violations = [];

        foreach ($this->phpFiles(app_path()) as $chemin) {
            $relatif = $this->relative($chemin);
            $inspectes++;

            if (in_array($relatif, self::ECRIVAINS, true)) {
                continue;
            }

            foreach (explode("\n", (string) file_get_contents($chemin)) as $numero => $ligne) {
                foreach (self::ECRITURES as $motif) {
                    if (preg_match($motif, $ligne) === 1) {
                        $violations[] = $relatif.':'.($numero + 1);
                    }
                }
            }
        }

        self::assertGreaterThan(100, $inspectes, 'La garde n\'a presque rien inspecté : elle ne prouverait rien.');
        self::assertSame([], $violations, 'La table classes s\'écrit hors de ses deux écrivains : '.implode(', ', $violations));
    }

    public function test_les_deux_ecrivains_ecrivent_bien(): void
    {
        // Sans cette preuve, un renommage des écrivains rendrait la garde muette.
        foreach (self::ECRIVAINS as $ecrivain) {
            $source = (string) file_get_contents(base_path($ecrivain));

            self::assertSame(1, preg_match(self::ECRITURES[0], $source), "{$ecrivain} n'écrit plus la table classes.");
        }
    }

    /** @return list<string> */
    private function phpFiles(string $racine): array
    {
        $fichiers = [];
        $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterateur as $fichier) {
            if ($fichier instanceof \SplFileInfo && $fichier->getExtension() === 'php') {
                $fichiers[] = $fichier->getPathname();
            }
        }

        sort($fichiers);

        return $fichiers;
    }

    private function relative(string $chemin): string
    {
        return ltrim(str_replace([base_path(), '\\'], ['', '/'], $chemin), '/');
    }
}
