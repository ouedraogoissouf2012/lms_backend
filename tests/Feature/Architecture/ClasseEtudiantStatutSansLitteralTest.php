<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Enums\ClasseEtudiantStatut;
use Tests\TestCase;

/**
 * Garde structurelle de #896 : le statut du pivot `classe_etudiant` passe par son
 * énumération, {@see ClasseEtudiantStatut}, jamais par le littéral
 * `'actif'` (CONTRIBUTING #522 : « Statut / état ⇒ enum backed string »).
 *
 * ## Ce qui reste permis, et pourquoi
 *
 * - Les énumérations qui DÉCLARENT la valeur : `ClasseEtudiantStatut`, et
 *   `EtatCodeInscription`, l'état d'un code d'inscription, qui a son propre `actif`.
 * - Les charges KLASSCI : le statut d'un élève dans une réponse de KLASSCI est le
 *   vocabulaire d'un système externe, pas notre énumération (KLASSCI écrit aussi
 *   `active`). L'issue les met hors de son périmètre. Chaque fichier est admis
 *   avec son nombre EXACT d'occurrences : un nouveau littéral du pivot écrit dans
 *   l'un d'eux reste refusé.
 *
 * Les commentaires ne comptent pas.
 */
final class ClasseEtudiantStatutSansLitteralTest extends TestCase
{
    private const ENUMERATIONS = [
        'app/Enums/ClasseEtudiantStatut.php',
        'app/Enums/EtatCodeInscription.php',
    ];

    /** Fichier => nombre exact de littéraux, tous dans une charge KLASSCI. */
    private const CHARGES_KLASSCI = [
        'app/Services/Classe/ClasseDetailsQueryService.php' => 1,
        'app/Services/Classe/ClasseEtudiantsQueryService.php' => 1,
        'app/Services/Seances/SeanceVisioEnricher.php' => 2,
    ];

    private const LITTERAL = '/[\'"]actif[\'"]/';

    public function test_le_statut_du_pivot_n_est_jamais_un_litteral(): void
    {
        $inspectes = 0;
        $violations = [];

        foreach ($this->phpFiles(app_path()) as $chemin) {
            $relatif = $this->relative($chemin);
            $inspectes++;

            if (in_array($relatif, self::ENUMERATIONS, true) || array_key_exists($relatif, self::CHARGES_KLASSCI)) {
                continue;
            }

            foreach ($this->litteraux($chemin) as $ligne) {
                $violations[] = $relatif.':'.$ligne;
            }
        }

        self::assertGreaterThan(100, $inspectes, 'La garde n\'a presque rien inspecté : elle ne prouverait rien.');
        self::assertSame([], $violations, 'Le statut du pivot s\'écrit en littéral : '.implode(', ', $violations));
    }

    public function test_les_charges_klassci_gardent_leur_nombre_exact(): void
    {
        foreach (self::CHARGES_KLASSCI as $fichier => $attendu) {
            self::assertCount(
                $attendu,
                $this->litteraux(base_path($fichier)),
                "{$fichier} : le nombre de littéraux a changé. Un littéral du pivot s'y est-il glissé, "
                .'ou la charge KLASSCI a-t-elle disparu ?',
            );
        }
    }

    /** @return list<int> Les numéros de ligne des littéraux, hors commentaires. */
    private function litteraux(string $chemin): array
    {
        $lignes = [];

        foreach (explode("\n", (string) file_get_contents($chemin)) as $numero => $ligne) {
            if (preg_match('#^\s*(\*|//|\#|/\*)#', $ligne) === 1) {
                continue;
            }

            if (preg_match(self::LITTERAL, $ligne) === 1) {
                $lignes[] = $numero + 1;
            }
        }

        return $lignes;
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
