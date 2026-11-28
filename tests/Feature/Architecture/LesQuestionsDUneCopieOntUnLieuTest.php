<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * #1702 (D2) : les questions d'une COPIE se lisent par `QuestionsDeLaCopie`, qui rend les questions
 * posées dans l'ordre figé au démarrage. Lire `->questions` d'une évaluation pour une copie
 * rendrait toute l'évaluation : le vivier d'un tirage (D3), ou un ordre qui n'est pas celui de
 * l'élève.
 *
 * Rouge si un fichier des évaluations qui manipule une copie (`EvaluationSubmission`) lit
 * `->questions` d'une évaluation sans figurer ci-dessous avec sa raison. `$this->questions…`
 * (un champ de service) n'est pas visé.
 */
final class LesQuestionsDUneCopieOntUnLieuTest extends TestCase
{
    /** Les lectures de l'évaluation ENTIÈRE, légitimes, et pourquoi. */
    private const EVALUATION_ENTIERE = [
        'app/Services/Evaluation/EvaluationGradingService.php' => 'evaluationRequiresManualGrading : l\'évaluation entière (synchronisation KLASSCI, recalcul) ; la copie a copieRequiresManualGrading',
        'app/Services/Evaluation/EvaluationListService.php' => 'le corrigé rendu visible à l\'enseignant ; l\'élève reçoit les questions de SA copie',
        'app/Services/Evaluation/QuestionsDeLaCopie.php' => 'le lieu lui-même',
        'app/Services/Evaluation/Teacher/TeacherEvaluationViewService.php' => 'l\'aperçu de l\'évaluation par l\'enseignant, hors de toute copie',
    ];

    private const DOSSIERS = ['app/Services/Evaluation', 'app/Http/Controllers/API/Evaluation', 'app/Http/Requests'];

    public function test_seuls_les_lecteurs_de_l_evaluation_entiere_lisent_ses_questions(): void
    {
        [$lecteurs, $lus] = $this->lecteurs();

        self::assertGreaterThan(50, $lus, 'Le balayage n\'a presque rien lu : il ne prouverait rien.');
        self::assertSame(array_keys(self::EVALUATION_ENTIERE), $lecteurs, 'Une copie doit lire ses questions par QuestionsDeLaCopie ('.$lus.' fichiers lus).');
    }

    /** @return array{list<string>, int} */
    private function lecteurs(): array
    {
        $lecteurs = [];
        $lus = 0;
        foreach (self::DOSSIERS as $dossier) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dossier))) as $fichier) {
                if (! $fichier instanceof SplFileInfo || $fichier->getExtension() !== 'php') {
                    continue;
                }
                $lus++;
                $source = (string) file_get_contents($fichier->getPathname());
                if (str_contains($source, 'EvaluationSubmission') && preg_match('/(?<!\$this)->questions\b/', $source) === 1) {
                    $lecteurs[] = str_replace('\\', '/', substr($fichier->getPathname(), strlen(base_path()) + 1));
                }
            }
        }
        sort($lecteurs);

        return [$lecteurs, $lus];
    }
}
