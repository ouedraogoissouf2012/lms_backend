<?php

declare(strict_types=1);

namespace Tests\Feature\Seances;

use App\Services\Seances\KlassciEmploiTempsSeances;
use Tests\TestCase;

/**
 * Garde structurel à CLIQUET : la clé morte `seances_programmees` ne peut plus
 * gagner de terrain, seulement en perdre.
 *
 * ## Le défaut que ce garde verrouille
 *
 * `matieres/{id}.data.seances_programmees` est **toujours vide** chez KLASSCI.
 * Mesuré le 2026-09-05 avec un jeton enseignant réel : `matieres/3` renvoyait
 * `seances_programmees: []` en annonçant, dans le MÊME corps de réponse,
 * `statistiques.seances.total_programmees: 28`.
 *
 * Douze fichiers lisaient cette clé. Chacun rendait donc, en permanence, zéro
 * séance — et la panne se propageait loin de sa cause :
 *
 * - « Séances 0 » sur l'espace enseignant et un calendrier vide (#741) ;
 * - la synchronisation ne confirmant AUCUNE séance, `StaleSeanceArchiver`
 *   archivait tout le tenant à chaque cycle sous le motif `supprimee_klassci` ;
 * - `classes_concernees` étant dérivé des séances, une matière n'avait aucune
 *   classe, le frontend envoyait `classe_id: null`, et la création de leçon
 *   échouait en **403 « This action is unauthorized »** — un message qui
 *   désigne une autorisation là où le problème est une donnée absente.
 *
 * Il a fallu remonter cette chaîne à l'envers, écran par écran. C'est ce coût
 * que ce fichier existe pour ne plus jamais payer.
 *
 * ## Pourquoi un cliquet plutôt qu'une interdiction sèche
 *
 * Cinq lecteurs subsistent, tracés par #739 et #740. Les interdire aujourd'hui
 * casserait la suite sans rien réparer. La liste ci-dessous est donc une
 * TOLÉRANCE, et elle ne peut que **rétrécir** :
 *
 * - migrer un lecteur → retirer sa ligne, le test reste vert ;
 * - en ajouter un nouveau → le test ROUGIT immédiatement.
 *
 * Le jour où la liste est vide, ce garde devient une interdiction pure.
 *
 * ## La bonne source
 *
 * {@see KlassciEmploiTempsSeances} — l'endpoint
 * `emploi-temps`, qui répond réellement, avec ses trois contraintes mesurées
 * (fenêtre obligatoire, `matiere_id` ignoré par KLASSCI, forme de payload
 * différente).
 *
 * @see PRODUCTION_STANDARDS.md §1.3 (tests obligatoires)
 */
final class DeadKlassciKeyRatchetTest extends TestCase
{
    /**
     * La clé morte, écrite de façon à ne pas se déclencher sur elle-même.
     */
    private const DEAD_KEY = 'seances_'.'programmees';

    /**
     * DETTE : lecteurs du payload KLASSCI, chacun tracé par une issue. Ils
     * lisent tous `…['data']['seances_programmees']`, c'est-à-dire l'enveloppe
     * de réponse de KLASSCI — donc toujours vide.
     *
     * Cette liste ne doit JAMAIS s'allonger, et elle DOIT pouvoir atteindre
     * zéro. C'est ce qui distingue un cliquet d'une liste d'exceptions.
     *
     * @var list<string>
     */
    private const TOLERES = [
        // #739 — l'activation visio résout la séance par cette clé, donc jamais.
        'app/Services/Visio/Lifecycle/VisioActivationService.php',

        // #740 — les lecteurs restants du chemin détail / page matière.
        'app/Http/Controllers/API/LMS/Concerns/FetchesSeanceDataFromKlassci.php',
        'app/Services/SeanceDetailQueryService.php',
        'app/Services/Seances/KlassciSeanceMatiereScanner.php',
    ];

    /**
     * PAS une dette : ces fichiers lisent NOTRE PROPRE réponse, où
     * `seances_programmees` est une clé de sortie légitime du contrat rendu au
     * frontend — pas le payload KLASSCI.
     *
     * Une revue adversariale a montré qu'ils étaient rangés avec les dettes.
     * Les confondre condamnait le cliquet : la liste `TOLERES` n'aurait jamais
     * pu atteindre zéro, et `test_the_tolerance_list_contains_no_stale_entry`
     * aurait épinglé pour toujours une ligne parfaitement correcte.
     *
     * Le détecteur ne sait pas distinguer « mon tableau » de « le tableau de
     * KLASSCI » — cette distinction-là est humaine, d'où la liste explicite.
     *
     * @var list<string>
     */
    private const CONTRAT_DE_SORTIE = [
        // `$data` vient de `getDetailsForUser()` : c'est notre réponse.
        'app/Http/Controllers/API/LMS/LMSMatieresQueryController.php',
    ];

    public function test_no_new_file_reads_the_dead_klassci_key(): void
    {
        $connus = array_merge(self::TOLERES, self::CONTRAT_DE_SORTIE);
        $nouveaux = array_values(array_diff($this->filesReadingDeadKey(), $connus));

        self::assertSame(
            [],
            $nouveaux,
            'Ces fichiers lisent `'.self::DEAD_KEY."`, une clé que KLASSCI laisse TOUJOURS vide :\n  - "
            .implode("\n  - ", $nouveaux)
            ."\n\nUtilise App\\Services\\Seances\\KlassciEmploiTempsSeances, la source qui répond réellement."
        );
    }

    /**
     * Le pendant du cliquet : une tolérance qui ne correspond plus à rien doit
     * être retirée. Sans cette moitié, la liste ne rétrécirait jamais et le
     * garde s'endormirait.
     */
    public function test_the_tolerance_list_contains_no_stale_entry(): void
    {
        $connus = array_merge(self::TOLERES, self::CONTRAT_DE_SORTIE);
        $obsoletes = array_values(array_diff($connus, $this->filesReadingDeadKey()));

        self::assertSame(
            [],
            $obsoletes,
            "Ces fichiers ne lisent plus la clé morte : retire-les de la liste de tolérance.\n  - "
            .implode("\n  - ", $obsoletes)
        );
    }

    /**
     * Les chemins, relatifs à la racine du dépôt, des fichiers de `app/` qui
     * LISENT la clé — les mentions en commentaire ne comptent pas.
     *
     * @return list<string>
     */
    private function filesReadingDeadKey(): array
    {
        $racine = base_path();
        $lecteurs = [];

        /** @var iterable<\SplFileInfo> $fichiers */
        $fichiers = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())),
            '/\.php$/'
        );

        foreach ($fichiers as $fichier) {
            $source = (string) file_get_contents($fichier->getPathname());

            if ($this->readsDeadKey($source)) {
                $chemin = str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1));
                $lecteurs[] = $chemin;
            }
        }

        sort($lecteurs);

        return $lecteurs;
    }

    /**
     * Une LECTURE, ni une mention ni une écriture.
     *
     * La version d'origine ne reconnaissait QUE l'accès de tableau
     * `$payload['seances_programmees']`. Une revue adversariale a montré
     * l'angle mort : `data_get($r, 'data.seances_programmees')` et
     * `Arr::get(...)` — deux formes courantes dans ce dépôt — passaient au
     * vert. Un garde qui ne voit qu'une syntaxe n'interdit rien, il déplace.
     *
     * Le détecteur reconnaît donc désormais la clé dans N'IMPORTE quelle
     * chaîne, y compris au sein d'un chemin pointé, après avoir retiré la
     * seule forme qui n'est pas une lecture : la flèche d'écriture.
     */
    private function readsDeadKey(string $source): bool
    {
        $cle = preg_quote(self::DEAD_KEY, '/');

        foreach (explode("\n", $source) as $ligne) {
            $nue = ltrim($ligne);

            // Commentaires et docblocks : ce sont des explications, pas des lectures.
            if ($nue === '' || str_starts_with($nue, '*') || str_starts_with($nue, '//') || str_starts_with($nue, '/*')) {
                continue;
            }

            // `'seances_programmees' =>` DÉFINIT une clé de notre propre
            // réponse : c'est le contrat rendu au frontend, pas une lecture du
            // payload KLASSCI. On l'ôte avant d'examiner le reste de la ligne —
            // ce qui laisse intacte une éventuelle lecture sur la même ligne.
            $reste = preg_replace('/[\'"]'.$cle.'[\'"]\s*=>/', '', $ligne) ?? $ligne;

            // Toute autre mention de la clé DANS une chaîne est une lecture :
            // `$p['seances_programmees']`, `data_get($p, 'data.seances_programmees')`,
            // `Arr::get($p, 'seances_programmees')`, `->get('seances_programmees')`.
            //
            // La clé doit être un SEGMENT ENTIER du chemin, jamais un fragment
            // d'identifiant : `'nb_seances_programmees'` et
            // `'nombre_seances_programmees'` sont des clés DIFFÉRENTES, de nos
            // propres réponses. Une première version sans cette ancre les
            // faisait rougir toutes les deux — un garde qui crie au loup finit
            // par être désactivé.
            if (preg_match('/[\'"](?:[^\'"]*\.)?'.$cle.'(?:\.[^\'"]*)?[\'"]/', $reste) === 1) {
                return true;
            }
        }

        return false;
    }
}
