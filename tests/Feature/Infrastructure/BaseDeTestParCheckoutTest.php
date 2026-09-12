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
     * de `estIntegre()` : `unlink` rend alors « Resource temporarily
     * unavailable ». Le ménage n'est pas ce que ce test prouve.
     */
    private function effacer(string $chemin): void
    {
        @unlink($chemin);
    }

    public function test_la_base_de_test_est_propre_a_ce_checkout(): void
    {
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
        $chemin = $this->enBarresObliques((string) config('database.connections.sqlite.database'));

        // Le fichier de dev a déjà été vidé une fois par erreur (2026-09-03).
        // Le sous-répertoire dédié rend la confusion de chemin impossible ;
        // cette assertion le grave.
        $this->assertStringNotContainsString('/database/database.sqlite', $chemin);
        $this->assertStringContainsString('/database/testing/', $chemin);
    }

    public function test_l_ancien_fichier_partage_n_est_plus_reference(): void
    {
        $phpunit = (string) file_get_contents(base_path('phpunit.xml'));

        // S'il revenait, deux worktrees repartageraient un fichier et la
        // corruption reviendrait avec.
        $this->assertStringNotContainsString('database.testing.sqlite', $phpunit);
    }

    public function test_un_fichier_vide_est_considere_comme_integre(): void
    {
        // C'est l'état d'une base pas encore migrée, pas celui d'une base
        // abîmée. Les confondre ferait écarter un fichier neuf à chaque
        // première exécution — et la garde deviendrait du bruit.
        $vide = tempnam(sys_get_temp_dir(), 'base692');
        $this->assertIsString($vide);

        $this->assertTrue(estIntegre($vide));

        $this->effacer($vide);
    }

    public function test_un_fichier_malforme_est_detecte(): void
    {
        $malforme = tempnam(sys_get_temp_dir(), 'base692');
        $this->assertIsString($malforme);
        // Un en-tête SQLite crédible suivi d'octets qui n'en sont pas : c'est la
        // forme qu'a un fichier écrit par deux processus à la fois.
        file_put_contents($malforme, "SQLite format 3\0".random_bytes(512));

        $this->assertFalse(estIntegre($malforme));

        $this->effacer($malforme);
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

        $this->assertTrue(estIntegre($saine));

        $this->effacer($saine);
    }
}
