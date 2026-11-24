<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * #738 — un entier de configuration ou d'option se lit par `LecteurDEntiers`, et par lui
 * seul. Sept copies privées de `positiveInt` décidaient chacune, dans leur coin, comment
 * interpréter une valeur mal saisie.
 *
 * Est compté dans `app/` (hors `LecteurDEntiers`) : une méthode `positiveInt…` ou
 * `nonNegativeInt…`, et un `(int) config(…)`. Le dénominateur est publié : « rien à
 * redire » et « rien regardé » ne produisent pas le même message.
 *
 * Hors de cette garde, déclaré (#738) : trois `(int) $this->option(…)` qui ne sont pas des
 * copies. `klassci:backfill-*` valident ensuite leur `--chunk` et échouent en le disant ;
 * `evaluations:notify-upcoming --hours` mérite son propre examen.
 */
final class UneSeuleLectureDEntierDeConfigTest extends TestCase
{
    private const MOTIFS = [
        'copie privée' => '/function\s+(positiveInt|nonNegativeInt)\w*\s*\(/i',
        'cast de config()' => '/\(int\)\s*config\s*\(/i',
    ];

    public function test_aucune_lecture_d_entier_hors_du_lecteur(): void
    {
        $fichiers = Finder::create()->files()->in(base_path('app'))->name('*.php')
            ->notPath('Support/Config/LecteurDEntiers.php');

        $lus = 0;
        $fautes = [];
        foreach ($fichiers as $fichier) {
            $lus++;
            $contenu = $fichier->getContents();
            foreach (self::MOTIFS as $nom => $motif) {
                if (preg_match($motif, $contenu) === 1) {
                    $fautes[] = $nom.' : app/'.str_replace('\\', '/', $fichier->getRelativePathname());
                }
            }
        }

        self::assertGreaterThan(500, $lus, "Seulement {$lus} fichiers lus dans app/ : le test ne regarde pas où il croit.");
        self::assertSame([], $fautes, 'Un entier se lit par App\\Support\\Config\\LecteurDEntiers (#738).');
    }
}
