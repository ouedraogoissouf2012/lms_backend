<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Garde structurelle de #955 : la date de fin d'un chapitre n'a qu'UN écrivain.
 *
 * `chapter_progress.completed_at` est la date de la PREMIÈRE complétion. Elle ne
 * se pose que dans `ChapterProgress::terminer()`, qui ne l'écrase jamais. Deux
 * écrivains avaient deux règles : la revalidation d'un chapitre déplaçait la
 * date (`ChapterProgressService::markAsCompleted`), la soumission d'un quiz la
 * gardait (`KnowledgeCheckAttemptService`).
 *
 * ## Ce qui est refusé hors du modèle
 *
 * L'affectation de propriété `->completed_at =`, quelle que soit sa valeur.
 *
 * ## Ce qui n'est PAS signalé, et pourquoi
 *
 * Les colonnes HOMONYMES d'autres tables s'écrivent par tableau
 * (`'completed_at' => now()` : la tentative de quiz dans
 * `KnowledgeCheckOpenAttempts`, la progression de leçon dans
 * `LessonProgressService`) : elles ne sont pas la date de fin d'un chapitre.
 */
final class ChapterCompletionSingleWriterTest extends TestCase
{
    private const PROPRIETAIRE = 'app/Models/ChapterProgress.php';

    public function test_la_date_de_fin_d_un_chapitre_n_a_qu_un_ecrivain(): void
    {
        $inspectes = 0;
        $violations = [];

        foreach ($this->phpFiles(app_path()) as $chemin) {
            $relatif = $this->relative($chemin);
            $inspectes++;

            if ($relatif === self::PROPRIETAIRE) {
                continue;
            }

            foreach (explode("\n", (string) file_get_contents($chemin)) as $numero => $ligne) {
                if (preg_match('/->completed_at\s*=(?!=)/', $ligne) === 1) {
                    $violations[] = $relatif.':'.($numero + 1);
                }
            }
        }

        self::assertGreaterThan(100, $inspectes, 'La garde n\'a presque rien inspecté : elle ne prouverait rien.');
        self::assertSame([], $violations, 'completed_at s\'écrit hors de ChapterProgress::terminer() : '.implode(', ', $violations));
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
