<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use RuntimeException;
use Tests\TestCase;

/**
 * #692 — une seule suite à la fois dans un même checkout.
 *
 * ## Le défaut qui restait
 *
 * {@see BaseDeTestParCheckoutTest} a fermé le cas de deux worktrees DIFFÉRENTS : chacun a
 * sa base, dérivée de son chemin. Deux suites lancées dans le MÊME répertoire retombaient
 * sur le même fichier, et le bootstrap l'écrivait lui-même : « Un verrou de répertoire
 * refusant une seconde suite dans le même checkout la fermerait ; ce n'est pas fait ici. »
 *
 * Mesuré le 23/11 sur une COPIE : `.lms-wt-reparateur` a mis en quarantaine, le 14/11 à
 * 02:27Z, une base de 28 Ko que SQLite déclare « database disk image is malformed », deux
 * mois après le correctif par checkout. Le reflog de ce worktree ne montre aucun checkout
 * entre 21:00Z et 02:58Z : la cause compatible est deux suites dans le même répertoire.
 * C'est une hypothèse, pas une preuve.
 *
 * ## Comment ces tests le prouvent
 *
 * Ils lancent le VRAI `tests/bootstrap.php`, copié dans un checkout factice, dans des
 * processus `php` séparés : une tenante qui reste en vie, puis une seconde. Rien n'est
 * simulé : c'est le système d'exploitation qui refuse ou accorde le verrou. Le checkout
 * factice a sa propre empreinte, donc ces tests ne touchent jamais la base de la suite
 * qui les exécute. Le moteur est forcé à `sqlite` dans les sous-processus : la jambe MySQL
 * de la CI prouve donc la même chose que les deux autres.
 *
 * @see tests/bootstrap.php
 */
final class UneSeuleSuiteParCheckoutTest extends TestCase
{
    private string $checkout = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkout = sys_get_temp_dir().DIRECTORY_SEPARATOR.'suite692-'.bin2hex(random_bytes(6));
        mkdir($this->checkout.'/tests', 0o775, true);
        mkdir($this->checkout.'/vendor', 0o775, true);

        copy(base_path('tests/bootstrap.php'), $this->checkout.'/tests/bootstrap.php');
        file_put_contents(
            $this->checkout.'/vendor/autoload.php',
            '<?php return require '.var_export(base_path('vendor/autoload.php'), true).';'.PHP_EOL,
        );
        file_put_contents(
            $this->checkout.'/tenante.php',
            '<?php require __DIR__."/tests/bootstrap.php"; echo "prete\t", getenv(VERROU_HERITE), "\n";'
            .' fflush(STDOUT); fgets(STDIN);'.PHP_EOL,
        );
        file_put_contents(
            $this->checkout.'/seconde.php',
            '<?php require __DIR__."/tests/bootstrap.php"; echo "amorcee\n";'.PHP_EOL,
        );
    }

    protected function tearDown(): void
    {
        $this->effacer($this->checkout);

        parent::tearDown();
    }

    public function test_une_seconde_suite_dans_le_meme_checkout_est_refusee(): void
    {
        $tenante = $this->tenante();

        [$code, $sortie] = $this->seconde();
        $this->relacher($tenante);

        $this->assertSame(1, $code, 'La seconde suite doit s\'arrêter : '.$sortie);
        // Le message de quarantaine cite déjà #692 : c'est la phrase du REFUS qu'on exige.
        $this->assertStringContainsString('Une autre suite tourne dans ce checkout', $sortie);
        $this->assertStringNotContainsString('amorcee', $sortie, 'Le refus arrive AVANT la suite.');
    }

    public function test_le_refus_arrive_avant_toute_ecriture(): void
    {
        $tenante = $this->tenante();

        // La base de la tenante est abîmée PENDANT qu'elle tourne. Sans verrou, la seconde
        // suite la déclare corrompue et la renomme sous les pieds de la première : la garde
        // d'intégrité devient elle-même l'écriture concurrente.
        $base = $this->base();
        file_put_contents($base, "SQLite format 3\0".str_repeat("\xAB", 512));

        [$code] = $this->seconde();
        $this->relacher($tenante);

        $this->assertSame(1, $code);
        $this->assertFileExists($base, 'La base de la suite en cours a été déplacée.');
        $this->assertSame([], glob($base.'.corrompue-*'), 'La seconde suite a écrit avant de s\'arrêter.');
    }

    /**
     * Un test `#[RunInSeparateProcess]` relance le bootstrap dans un enfant que la suite
     * attend. Premier jet du correctif sans ce cas : `SessionSecureCookieDefaultTest` refusé
     * sur les deux jambes sqlite. L'enfant hérite du chemin EXACT du verrou de sa suite.
     */
    public function test_un_processus_isole_de_la_meme_suite_passe(): void
    {
        $tenante = $this->tenante();

        [$code, $sortie] = $this->seconde([VERROU_HERITE => $tenante[2]]);
        $this->relacher($tenante);

        $this->assertSame(0, $code, 'Un processus isolé de la suite ne la concurrence pas : '.$sortie);
        $this->assertStringContainsString('amorcee', $sortie);
    }

    public function test_le_verrou_rendu_la_suite_suivante_passe(): void
    {
        $this->relacher($this->tenante());

        [$code, $sortie] = $this->seconde();

        $this->assertSame(0, $code, 'Un verrou rendu doit laisser passer : '.$sortie);
        $this->assertStringContainsString('amorcee', $sortie);
    }

    /**
     * Lance une suite qui garde le verrou jusqu'à ce qu'on ferme son entrée standard.
     *
     * Rend aussi le chemin du verrou qu'elle tient, tel qu'elle le transmet à ses enfants.
     *
     * @return array{0: resource, 1: array<int, resource>, 2: string}
     */
    private function tenante(): array
    {
        $processus = proc_open([PHP_BINARY, 'tenante.php'], $this->tuyaux(), $tuyaux, $this->checkout, $this->environnement());

        if (! is_resource($processus)) {
            throw new RuntimeException('La suite tenante n\'a pas démarré.');
        }

        $ligne = (string) fgets($tuyaux[1]);

        if (! str_starts_with($ligne, "prete\t")) {
            throw new RuntimeException('La suite tenante n\'a pas pris le verrou : '.stream_get_contents($tuyaux[2]));
        }

        return [$processus, $tuyaux, rtrim(substr($ligne, 6), "\r\n")];
    }

    /** @param  array{0: resource, 1: array<int, resource>, 2: string}  $tenante */
    private function relacher(array $tenante): void
    {
        [$processus, $tuyaux] = $tenante;

        fclose($tuyaux[0]);
        stream_get_contents($tuyaux[1]);
        stream_get_contents($tuyaux[2]);
        proc_close($processus);
    }

    /**
     * @param  array<string, string>  $heritage  variables qu'un parent PHPUnit transmettrait
     * @return array{0: int, 1: string}
     */
    private function seconde(array $heritage = []): array
    {
        $environnement = array_merge($this->environnement(), $heritage);
        $processus = proc_open([PHP_BINARY, 'seconde.php'], $this->tuyaux(), $tuyaux, $this->checkout, $environnement);

        if (! is_resource($processus)) {
            throw new RuntimeException('La seconde suite n\'a pas démarré.');
        }

        fclose($tuyaux[0]);
        $sortie = stream_get_contents($tuyaux[1]).stream_get_contents($tuyaux[2]);

        return [proc_close($processus), $sortie];
    }

    /** @return array<int, array{0: string, 1: string}> */
    private function tuyaux(): array
    {
        return [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    }

    /** @return array<string, string> */
    private function environnement(): array
    {
        return array_merge(getenv(), ['DB_CONNECTION' => 'sqlite']);
    }

    /** La base que le bootstrap dérive du checkout factice, quel que soit son nom exact. */
    private function base(): string
    {
        $bases = glob($this->checkout.'/database/testing/*.sqlite') ?: [];
        $this->assertCount(1, $bases, 'La tenante devait avoir créé sa base.');

        return $bases[0];
    }

    /**
     * Ménage sans faire échouer le test : sous Windows, une poignée peut survivre un
     * instant à son processus. Le ménage n'est pas ce que ces tests prouvent.
     */
    private function effacer(string $chemin): void
    {
        if (is_dir($chemin)) {
            foreach (scandir($chemin) ?: [] as $entree) {
                if ($entree !== '.' && $entree !== '..') {
                    $this->effacer($chemin.DIRECTORY_SEPARATOR.$entree);
                }
            }
            @rmdir($chemin);

            return;
        }

        @unlink($chemin);
    }
}
