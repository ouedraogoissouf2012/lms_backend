<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use PDO;
use Tests\TestCase;

/**
 * #692 — la base de test appartient à CE checkout, et une corruption se dit.
 *
 * ## Le défaut
 *
 * `phpunit.xml` pointait `database/database.testing.sqlite`, un fichier UNIQUE.
 * Le travail se fait en plusieurs worktrees en parallèle : deux exécutions
 * concurrentes écrivaient le même fichier SQLite. Corruption classique, arrivée
 * au moins deux fois — une sauvegarde `…corrupt-backup-20260712-restore`
 * traînait déjà dans `database/`.
 *
 * Une exécution a rendu **94 erreurs** sur des tests sans aucun rapport entre
 * eux :
 *
 *     SQLSTATE[HY000]: General error: 11 database disk image is malformed
 *
 * Le message ne désigne pas la cause. Devant 94 rouges sur des fichiers qu'on
 * n'a pas touchés, on part chercher une régression qui n'existe pas.
 *
 * ## Ce que ce fichier verrouille
 *
 * Deux choses, et elles sont de nature différente : la première rend le défaut
 * IMPOSSIBLE, la seconde le rend LISIBLE s'il survient par un autre chemin.
 *
 * @see tests/bootstrap.php
 */
final class BaseDeTestParCheckoutTest extends TestCase
{
    /** Windows et POSIX ne s'accordent pas sur le séparateur ; la comparaison, si. */
    private function enBarresObliques(string $chemin): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', $chemin);
    }

    /**
     * Efface un fichier temporaire sans faire échouer le test s'il résiste.
     *
     * Sous Windows, la poignée ouverte par PDO peut survivre brièvement à la fin
     * de `verdictIntegrite()` : `unlink` rend alors « Resource temporarily
     * unavailable ». Le ménage n'est pas ce que ce test prouve.
     */
    private function effacer(string $chemin): void
    {
        @unlink($chemin);
    }

    /**
     * La garantie porte sur la base SQLite. La jambe MySQL de la CI pointe une
     * base nommée (`lms_testing`), où un chemin de fichier n'a pas de sens — et
     * où la corruption par concurrence ne se pose pas : le serveur arbitre les
     * écritures.
     *
     * Sauter est ici la bonne réponse, pas un contournement. Les deux premiers
     * jets de cette PR l'ont appris à leurs dépens : le premier cassait la
     * connexion MySQL, le second y faisait échouer ces deux assertions.
     */
    private function exigeSqlite(): void
    {
        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Garantie propre au moteur SQLite.');
        }
    }

    public function test_la_base_de_test_est_propre_a_ce_checkout(): void
    {
        $this->exigeSqlite();
        $chemin = (string) config('database.connections.sqlite.database');

        // L'empreinte du chemin racine : deux worktrees ne peuvent pas tomber
        // sur le même fichier, donc le cas concurrent disparaît par
        // construction — il n'est pas seulement rendu moins probable.
        $attendue = substr(sha1(base_path()), 0, 12);

        $this->assertSame(
            $this->enBarresObliques(base_path('database/testing/'.$attendue.'.sqlite')),
            $this->enBarresObliques($chemin),
            'La suite doit écrire dans une base dérivée du chemin du checkout.'
        );
    }

    public function test_elle_n_est_jamais_la_base_de_developpement(): void
    {
        $this->exigeSqlite();
        $chemin = $this->enBarresObliques((string) config('database.connections.sqlite.database'));

        // Le fichier de dev a déjà été vidé une fois par erreur (2026-09-03).
        // Le sous-répertoire dédié rend la confusion de chemin impossible ;
        // cette assertion le grave.
        $this->assertStringNotContainsString('/database/database.sqlite', $chemin);
        $this->assertStringContainsString('/database/testing/', $chemin);
    }

    /**
     * Ce test affirmait l'INVERSE, et c'était dangereux.
     *
     * Il exigeait que `database.testing.sqlite` disparaisse de `phpunit.xml`.
     * Mesuré depuis : sans cette ligne, une exécution lancée avec un autre
     * bootstrap — `vendor/autoload.php`, la valeur que ce fichier portait avant
     * #692, donc celle que reprend toute configuration d'IDE créée avant —
     * résout `database/database.sqlite`, la base de DÉVELOPPEMENT.
     *
     *     avec le filet ......... database/database.testing.sqlite
     *     sans le filet ......... database/database.sqlite          ← la base de DEV
     *
     * L'invariant correct n'est pas « l'ancien chemin doit disparaître » mais
     * « une exécution égarée doit atterrir sur une base de TEST ». Le filet ne
     * gagne jamais contre `tests/bootstrap.php`, qui s'exécute après lui.
     */
    public function test_phpunit_garde_un_filet_vers_une_base_de_test(): void
    {
        $phpunit = (string) file_get_contents(base_path('phpunit.xml'));

        $this->assertStringContainsString(
            '<env name="DB_DATABASE" value="database/database.testing.sqlite"/>',
            $phpunit,
            'Le filet a été retiré : une exécution avec un bootstrap étranger '
            .'pointerait la base de développement.'
        );
    }

    public function test_un_fichier_vide_est_considere_comme_integre(): void
    {
        // C'est l'état d'une base pas encore migrée, pas celui d'une base
        // abîmée. Les confondre ferait écarter un fichier neuf à chaque
        // première exécution — et la garde deviendrait du bruit.
        $vide = tempnam(sys_get_temp_dir(), 'base692');
        $this->assertIsString($vide);

        $this->assertSame('saine', verdictIntegrite($vide));

        $this->effacer($vide);
    }

    public function test_un_fichier_malforme_est_detecte(): void
    {
        $malforme = tempnam(sys_get_temp_dir(), 'base692');
        $this->assertIsString($malforme);
        // Un en-tête SQLite crédible suivi d'octets qui n'en sont pas : c'est la
        // forme qu'a un fichier écrit par deux processus à la fois.
        file_put_contents($malforme, "SQLite format 3\0".random_bytes(512));

        $this->assertSame('corrompue', verdictIntegrite($malforme));

        $this->effacer($malforme);
    }

    /**
     * La branche pour laquelle le verdict à trois valeurs existe — et la seule
     * qui n'était pas couverte au premier jet.
     *
     * Un verrou n'est PAS une corruption. Une base saine tenue par un pair en
     * transaction faisait expirer le délai d'attente, tombait dans le `catch`,
     * et se faisait mettre en quarantaine. Sous Linux le renommage réussit : la
     * base de l'exécution en cours était déplacée sous ses pieds.
     */
    public function test_un_verrou_n_est_pas_une_corruption(): void
    {
        $this->assertSame('indeterminable', verdictDepuisCode('database is locked'));
        $this->assertSame('indeterminable', verdictDepuisCode('unable to open database file'));
        $this->assertSame('indeterminable', verdictDepuisCode('disk I/O error'));
    }

    /**
     * Régression fermée : `unsupported file format` n'était PAS dans la liste.
     *
     * Le code remplacé attrapait tout `Throwable` et écartait donc ce cas ; mon
     * premier jet le rendait `indeterminable`, laissait le fichier en place, et
     * la suite repartait dessus — sans une ligne de l'amorçage. Le symptôme même
     * que #692 supprime.
     *
     * Mesuré : un seul octet modifié à l'offset 47 produit ce message.
     */
    public function test_toutes_les_formes_de_corruption_connues_sont_attrapees(): void
    {
        foreach ([
            'database disk image is malformed',
            'file is not a database',
            'file is encrypted or is not a database',
            'unsupported file format',
        ] as $message) {
            $this->assertSame('corrompue', verdictDepuisCode($message), $message);
        }
    }

    public function test_une_base_saine_est_reconnue(): void
    {
        // Le versant positif compte autant : une garde qui crie sur du sain est
        // désactivée dans la semaine.
        $saine = tempnam(sys_get_temp_dir(), 'base692');
        $this->assertIsString($saine);
        $pdo = new PDO('sqlite:'.$saine);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        unset($pdo);

        $this->assertSame('saine', verdictIntegrite($saine));

        $this->effacer($saine);
    }
}
