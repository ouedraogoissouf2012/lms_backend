<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Garde structurelle (#967, point 6) : le score de quiz d'un chapitre n'a
 * qu'UN écrivain.
 *
 * `chapter_progress.quiz_score` garde le MEILLEUR score. Il ne s'écrit que dans
 * `KnowledgeCheckAttemptService::updateChapterProgress()`, DANS la transaction
 * de soumission (`submitAttempt`) : la transaction de
 * `ChapterTimeRecorder::record()` y est imbriquée, ses verrous `lockForUpdate`
 * tiennent jusqu'au commit englobant, et la comparaison « le meilleur gagne »
 * se fait sous verrou. Deux soumissions simultanées sont sérialisées.
 *
 * Un second écrivain, hors de cette transaction, rouvrirait la course : il
 * lirait un score périmé et pourrait écraser le meilleur. C'est ce que faisait
 * `ChapterProgressService::recordQuizScore()`, sans route, retiré par #1109.
 *
 * ## Ce qui est refusé hors du propriétaire
 *
 * - l'affectation `->quiz_score =` ;
 * - `->increment(` / `->decrement(` sur `quiz_score` ;
 * - un appel d'écriture Eloquent (`create`, `update`, `fill`, `forceFill`,
 *   `insert`, `upsert`, `updateOrCreate`, `firstOrCreate`, `insertOrIgnore`)
 *   dont le tableau, dans la même instruction, porte la clé `quiz_score`.
 *
 * ## Ce qui n'est PAS signalé
 *
 * Les LECTURES (`'quiz_score' => $progress?->quiz_score` dans un tableau de
 * réponse), qui ne passent par aucun appel d'écriture, et les déclarations du
 * modèle (`$fillable`, `$casts`).
 *
 * ## Ce que la garde ne voit pas
 *
 * Un tableau préparé dans une variable puis passé à `update($data)`, et une
 * écriture par `DB::table(...)`. Une garde par motif lit des formes, et non
 * l'intention du code.
 */
final class QuizScoreSingleWriterTest extends TestCase
{
    private const PROPRIETAIRE = 'app/Services/KnowledgeCheck/KnowledgeCheckAttemptService.php';

    /** Déclarations du modèle (fillable, casts) : pas des écritures. */
    private const DECLARATIONS = ['app/Models/ChapterProgress.php'];

    public function test_le_score_de_quiz_n_a_qu_un_ecrivain(): void
    {
        $inspectes = 0;
        $violations = [];

        foreach ($this->phpFiles(app_path()) as $chemin) {
            $relatif = $this->relative($chemin);
            $inspectes++;

            if ($relatif === self::PROPRIETAIRE || in_array($relatif, self::DECLARATIONS, true)) {
                continue;
            }

            foreach ($this->violationsIn((string) file_get_contents($chemin)) as $forme) {
                $violations[] = $relatif.' : '.$forme;
            }
        }

        self::assertGreaterThan(100, $inspectes, 'La garde n\'a presque rien inspecté : elle ne prouverait rien.');
        self::assertSame(
            [],
            $violations,
            'quiz_score s\'écrit hors de son unique propriétaire ('.self::PROPRIETAIRE.') : '.implode(', ', $violations)
        );
    }

    /** La garde ROUGIT sur chaque forme d'écriture qu'elle doit refuser. */
    public function test_la_garde_rougit_sur_chaque_forme_d_ecriture(): void
    {
        $sources = [
            '$progress->quiz_score = $score;',
            '$p->increment(\'quiz_score\', 5);',
            "\$progress->update([\n    'quiz_passed' => true,\n    'quiz_score' => \$s,\n]);",
            "ChapterProgress::create(['user_id' => 1, 'quiz_score' => 90]);",
            "ChapterProgress::updateOrCreate(['user_id' => \$u], ['quiz_score' => \$n]);",
        ];

        foreach ($sources as $source) {
            self::assertNotSame([], $this->violationsIn('<?php '.$source), 'non détectée : '.$source);
        }
    }

    /** Et elle se TAIT sur les lectures. */
    public function test_la_garde_se_tait_sur_les_lectures(): void
    {
        $sources = [
            "return ['quiz_score' => \$progress?->quiz_score];",
            "'quiz_score' => \$progress?->quiz_score,",
            'if ($progress->quiz_score === null) { return; }',
            '$meilleur = $progress->quiz_score ?? 0;',
        ];

        foreach ($sources as $source) {
            self::assertSame([], $this->violationsIn('<?php '.$source), 'faux positif : '.$source);
        }
    }

    /**
     * @return list<string>
     */
    private function violationsIn(string $source): array
    {
        $formes = [];

        if (preg_match('/->quiz_score\s*=(?!=)/', $source) === 1) {
            $formes[] = 'affectation de propriété';
        }
        if (preg_match('/->(?:increment|decrement)\(\s*[\'"]quiz_score[\'"]/', $source) === 1) {
            $formes[] = 'increment/decrement';
        }
        $ecriture = '(?:create|update|fill|forceFill|insert|upsert|updateOrCreate|firstOrCreate|insertOrIgnore)';
        if (preg_match('/(?:->|::)'.$ecriture.'\s*\([^;]*?[\'"]quiz_score[\'"]\s*=>/s', $source) === 1) {
            $formes[] = 'écriture Eloquent';
        }

        return $formes;
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
