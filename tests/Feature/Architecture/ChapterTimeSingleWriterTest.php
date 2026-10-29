<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Models\LessonProgress;
use Tests\TestCase;

/**
 * Garde structurelle de #944 : le temps passé n'a qu'UN écrivain.
 *
 * `chapter_progress.time_spent_seconds` est la source ; `lesson_progress.
 * time_spent_minutes` en est l'agrégat recalculé. Les deux ne s'écrivent que
 * dans `app/Services/Chapter/ChapterTimeRecorder.php`, qui verrouille dans un
 * ordre unique et recalcule l'agrégat. Un second écrivain rouvrirait exactement
 * ce que #944 a fermé : un agrégat faux sur six écrans (le temps d'un quiz
 * oublié dans la somme, KnowledgeCheckAttemptService:259 avant #944), ou un
 * ordre de verrouillage croisé, donc un interblocage.
 *
 * ## Ce qui est refusé hors du propriétaire
 *
 * - `->increment(` / `->decrement(` sur l'un des deux champs ;
 * - l'affectation `->time_spent_seconds =` ou `->time_spent_minutes =` ;
 * - l'affectation par clé `['time_spent_…'] =` (le cumul client de
 *   LessonProgressService:105, avant #944) ;
 * - un appel d'écriture Eloquent (`create`, `update`, `fill`, `insert`,
 *   `upsert`, `updateOrCreate`, `firstOrCreate`, `insertOrIgnore`) dont le
 *   tableau porte l'une des deux clés avec une valeur autre que 0.
 *
 * ## Ce qui n'est PAS signalé, et pourquoi
 *
 * - les LECTURES (`'time_spent_minutes' => $progress->time_spent_minutes` dans
 *   un tableau de réponse) : elles ne passent par aucun appel d'écriture ;
 * - l'initialisation à 0 d'une ligne neuve (`firstOrCreate(…, ['time_spent_seconds'
 *   => 0])`) : ce n'est pas une écriture de temps ;
 * - les HOMONYMES, même nom de colonne mais une autre table, listés ci-dessous
 *   avec leur table. Cette liste ne doit pas s'allonger sans raison écrite.
 */
final class ChapterTimeSingleWriterTest extends TestCase
{
    private const PROPRIETAIRE = 'app/Services/Chapter/ChapterTimeRecorder.php';

    /**
     * Fichiers qui écrivent une colonne HOMONYME d'une autre table, avec la SEULE
     * forme tolérée pour chacun. Toute autre forme reste refusée dans ces mêmes
     * fichiers : KnowledgeCheckAttemptService est précisément l'ancien écrivain
     * fautif (`increment` à :259), il ne doit pas sortir de la garde.
     *
     * @var array<string, array{forme: string, table: string}>
     */
    private const HOMONYMES = [
        'app/Services/Quiz/QuizGradingService.php' => [
            'forme' => 'affectation de propriété',
            'table' => 'quiz_attempts.time_spent_seconds ($attempt->time_spent_seconds = …)',
        ],
        'app/Services/KnowledgeCheck/KnowledgeCheckAttemptService.php' => [
            'forme' => 'écriture Eloquent',
            'table' => 'knowledge_check_attempts.time_spent_seconds (KnowledgeCheckAttempt::create)',
        ],
    ];

    /** Déclarations du modèle (fillable, casts, défauts) : pas des écritures. */
    private const DECLARATIONS = [
        'app/Models/LessonProgress.php',
        'app/Models/ChapterProgress.php',
    ];

    public function test_le_temps_passe_n_a_qu_un_ecrivain(): void
    {
        $inspectes = 0;
        $violations = [];

        foreach ($this->phpFiles(app_path()) as $chemin) {
            $relatif = $this->relative($chemin);
            $inspectes++;

            if ($relatif === self::PROPRIETAIRE || in_array($relatif, self::DECLARATIONS, true)) {
                continue;
            }

            $toleree = self::HOMONYMES[$relatif]['forme'] ?? null;
            foreach ($this->violationsIn((string) file_get_contents($chemin)) as $forme) {
                if ($forme !== $toleree) {
                    $violations[] = $relatif.' : '.$forme;
                }
            }
        }

        // Le dénominateur (§1.1-bis) : un vert sans fichiers inspectés ne vaudrait rien.
        fwrite(STDERR, sprintf("\n[Temps passé] %d fichier(s) de app/ inspecté(s), %d violation(s)\n", $inspectes, count($violations)));
        self::assertGreaterThan(100, $inspectes, 'La garde n\'a presque rien inspecté : elle ne prouverait rien.');

        self::assertSame(
            [],
            $violations,
            "Le temps passé s'écrit hors de son unique propriétaire (".self::PROPRIETAIRE.") :\n  - "
            .implode("\n  - ", $violations)
            ."\n\nPasse par ChapterTimeRecorder::record(), resetLesson() ou recalculateLesson()."
        );
    }

    /**
     * Le motif statique ne lit pas un tableau préparé puis passé à `update()` :
     * `$data = ['time_spent_minutes' => …]; $p->update($data)`. Ce trou se ferme
     * à la source : l'agrégat est hors de `$fillable`, donc aucune assignation
     * de masse ne peut l'écrire.
     */
    public function test_l_agregat_ne_s_assigne_pas_en_masse(): void
    {
        self::assertNotContains('time_spent_minutes', (new LessonProgress)->getFillable());
    }

    /** La garde ROUGIT sur chaque forme d'écriture qu'elle doit refuser. */
    public function test_la_garde_rougit_sur_chaque_forme_d_ecriture(): void
    {
        $sources = [
            '$p->increment(\'time_spent_seconds\', $n);',
            '$p->decrement("time_spent_minutes", 1);',
            '$progress->time_spent_minutes = 5;',
            '$data[\'time_spent_minutes\'] = $old + $n;',
            "\$progress->update([\n    'progress_percentage' => 10,\n    'time_spent_minutes' => \$t,\n]);",
            "ChapterProgress::create(['user_id' => 1, 'time_spent_seconds' => \$s]);",
        ];

        foreach ($sources as $source) {
            self::assertNotSame([], $this->violationsIn('<?php '.$source), 'non détectée : '.$source);
        }
    }

    /** Et elle se TAIT sur les lectures et l'initialisation à 0. */
    public function test_la_garde_se_tait_sur_les_lectures_et_l_initialisation(): void
    {
        $sources = [
            "return ['time_spent_minutes' => \$progress->time_spent_minutes];",
            "'time_spent_seconds' => \$progress?->time_spent_seconds ?? 0,",
            "ChapterProgress::firstOrCreate(['user_id' => \$u, 'chapter_id' => \$c], ['time_spent_seconds' => 0]);",
            '$total = $progress->time_spent_minutes + 1;',
            "->sum('time_spent_minutes')",
        ];

        foreach ($sources as $source) {
            self::assertSame([], $this->violationsIn('<?php '.$source), 'faux positif : '.$source);
        }
    }

    /**
     * @return list<string> les formes d'écriture trouvées
     */
    private function violationsIn(string $source): array
    {
        $champ = 'time_spent_(?:seconds|minutes)';
        $motifs = [
            'increment/decrement' => '/->(?:increment|decrement)\(\s*[\'"]'.$champ.'[\'"]/',
            'affectation de propriété' => '/->'.$champ.'\s*=(?!=)/',
            'affectation par clé' => '/\[\s*[\'"]'.$champ.'[\'"]\s*\]\s*=(?!=)/',
            'écriture Eloquent' => '/(?:create|update|fill|insert|upsert|updateOrCreate|firstOrCreate|insertOrIgnore)\s*\(\s*\[[^;]*?[\'"]'.$champ.'[\'"]\s*=>(?!\s*0\s*[\],])/s',
        ];

        $trouvees = [];
        foreach ($motifs as $nom => $motif) {
            if (preg_match($motif, $source) === 1) {
                $trouvees[] = $nom;
            }
        }

        return $trouvees;
    }

    /**
     * @return list<string>
     */
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
