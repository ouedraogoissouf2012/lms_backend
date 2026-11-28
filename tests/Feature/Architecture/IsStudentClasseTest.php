<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * #964 : `isStudent()` ne vaut vrai que sur un rôle RECONNU. Écrit `if (isStudent())
 * { restreindre }`, il OUVRE l'accès par défaut à tout autre rôle, non reconnu
 * compris. La vue par défaut s'écrit `isLearnerAudience()` (#941).
 *
 * Chaque appel restant d'`app/` est donc CLASSÉ ci-dessous : fermé par défaut
 * (`if (isStudent()) { autoriser }` : un rôle non reconnu n'obtient rien) ou sans
 * effet. Un appel nouveau, ou un appel de plus dans un fichier classé, rougit ici :
 * il doit être relu et classé, ou écrit `isLearnerAudience()`. Le dénominateur est
 * publié.
 */
final class IsStudentClasseTest extends TestCase
{
    /** @var array<string, array{0: int, 1: string}> fichier => [appels, classement] */
    private const CLASSES = [
        'app/Http/Requests/HideSeanceRequest.php' => [1, 'fermé : seul un élève masque une séance'],
        'app/Http/Requests/UnhideSeanceRequest.php' => [1, 'fermé : seul un élève démasque une séance'],
        'app/Http/Requests/DeposerUneReclamationRequest.php' => [1, 'fermé : seul un élève réclame une note'],
        'app/Http/Requests/StartEvaluationRequest.php' => [1, 'fermé : seul un élève démarre une évaluation'],
        'app/Http/Requests/SubmitEvaluationRequest.php' => [1, 'fermé : seul un élève rend une copie'],
        'app/Services/Chapter/ChapterReadGate.php' => [1, 'fermé : hors élève, la leçon est introuvable'],
        'app/Services/Seances/Mutations/ParticipantValidationService.php' => [1, 'fermé : hors personnel et élève, refus'],
        'app/Services/Visio/VisioActorAuthorization.php' => [1, 'fermé : l élève ne valide que lui-même'],
        'app/Services/Visio/Recording/SeanceRecordingAccessService.php' => [1, 'fermé : hors élève, refus'],
        'app/Services/Attendances/VideoSessionAttendancesSyncer.php' => [1, 'fermé : seul un élève inscrit est compté'],
        'app/Services/Audience/ElevesDeLaClasse.php' => [1, 'fermé : seul un élève est averti'],
        'app/Services/Seances/LigneDuRepliLocal.php' => [1, 'fermé : l étape suivante exige la gestion ou l audience'],
        'app/Services/Klassci/Auth/SynchrosDuLogin.php' => [1, 'fermé : hors élève, pas de synchronisation de classes'],
        'app/Services/Evaluation/Classe/AccesAuBilanDeClasse.php' => [1, 'fermé : hors personnel et élève reconnu, refus 403'],
        'app/Services/Seances/UserOwnMatieresResolver.php' => [1, 'sans effet : choisit l endpoint, KLASSCI répond selon le jeton'],
    ];

    public function test_chaque_appel_a_is_student_est_classe(): void
    {
        $inspectes = 0;
        $ecarts = [];
        $trouves = [];

        foreach ($this->fichiersPhp() as $chemin) {
            $inspectes++;
            $appels = $this->appels($chemin);
            if ($appels === 0) {
                continue;
            }
            $trouves[$chemin] = $appels;
            $attendu = self::CLASSES[$chemin][0] ?? 0;
            if ($appels > $attendu) {
                $ecarts[] = "{$chemin} : {$appels} appel(s), {$attendu} classé(s)";
            }
        }

        self::assertGreaterThan(100, $inspectes, "seulement {$inspectes} fichier(s) inspecté(s) : la garde ne regarde rien");
        self::assertSame([], $ecarts, "{$inspectes} fichier(s) inspecté(s). `isStudent()` non classé (#964) : "
            ."classe-le dans CLASSES, ou écris isLearnerAudience() :\n".implode("\n", $ecarts));
        self::assertSame(array_sum(array_column(self::CLASSES, 0)), array_sum($trouves), 'un appel classé a disparu : mets CLASSES à jour');
    }

    /** Les appels de méthode `->isStudent(` / `?->isStudent(`, hors commentaires. */
    private function appels(string $chemin): int
    {
        $jetons = token_get_all((string) file_get_contents(base_path($chemin)));
        $n = 0;
        foreach ($jetons as $i => $jeton) {
            if (is_array($jeton) && $jeton[0] === T_STRING && $jeton[1] === 'isStudent') {
                $avant = $jetons[$i - 1] ?? null;
                if (is_array($avant) && in_array($avant[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                    $n++;
                }
            }
        }

        return $n;
    }

    /** @return iterable<string> */
    private function fichiersPhp(): iterable
    {
        $racine = base_path('app');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine)) as $fichier) {
            if ($fichier instanceof SplFileInfo && $fichier->getExtension() === 'php') {
                yield 'app/'.str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1));
            }
        }
    }
}
