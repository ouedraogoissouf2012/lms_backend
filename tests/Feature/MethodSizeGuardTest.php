<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * #1455 : le garde de longueur de méthode (`scripts/check-method-sizes.php`) suivait
 * l'imbrication en comptant les caractères `{` et `}`. Une interpolation `"{$x}"` produit un
 * jeton `T_CURLY_OPEN`, que le garde ne comptait pas comme ouvrant, alors que son `}` fermait :
 * le corps se fermait trop tôt, et la méthode était mesurée trop COURTE.
 *
 * Mesuré sur `app/` : quatre méthodes au-delà de 40 lignes passaient ainsi le garde.
 *
 * @see scripts/check-method-sizes.php
 * @see tests/Feature/FileSizeGuardTest.php (le harnais `exec` repris ici)
 */
final class MethodSizeGuardTest extends TestCase
{
    public function test_une_methode_longue_avec_une_interpolation_accolade_est_signalee(): void
    {
        [$code, $sortie] = $this->garde([$this->classe('Accolade', [$this->methode('longue', 45, '"{$a}"')])]);

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('Accolade.php::longue', $sortie);
    }

    public function test_une_methode_longue_avec_une_interpolation_dollar_accolade_est_signalee(): void
    {
        [$code, $sortie] = $this->garde([$this->classe('DollarAccolade', [$this->methode('longue', 45, '"${a}"')])]);

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('DollarAccolade.php::longue', $sortie);
    }

    /** Verrou : sous la limite, une interpolation ne fait pas signaler la méthode. */
    public function test_une_methode_courte_avec_une_interpolation_passe(): void
    {
        [$code, $sortie] = $this->garde([$this->classe('Courte', [$this->methode('courte', 38, '"{$a}"')])]);

        $this->assertSame(0, $code, $sortie);
    }

    /** Verrou : la méthode qui suit une interpolation est mesurée pour elle-même. */
    public function test_la_methode_suivante_reste_mesuree_a_part(): void
    {
        [$code, $sortie] = $this->garde([$this->classe('Voisines', [
            $this->methode('courte', 5, '"{$a}"'),
            $this->methode('longue', 45, '1'),
        ])]);

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('Voisines.php::longue', $sortie);
        $this->assertStringNotContainsString('Voisines.php::courte', $sortie);
    }

    /**
     * Les méthodes de `app/` que le garde corrigé a fait apparaître tiennent sous 40 lignes.
     *
     * `KlassciHttpClient::executeHttp` (44) les a rejointes après la fusion de #1477, qui
     * travaillait ce fichier ; `BackfillEnseignantIdCommand::handle` (49) est ramenée sous 40
     * par #1457, et rejoindra ce test après sa fusion.
     *
     * Chemins RELATIFS, depuis la racine, comme la CI les passe : la baseline des dettes
     * tolérées est indexée par `app/…::méthode`. Un chemin absolu la contourne, et
     * `SeanceVisioEnricher::loadFromLocalDbFallback` (42, tolérée) passerait pour une violation.
     */
    public function test_les_methodes_revelees_par_le_garde_corrige_tiennent(): void
    {
        [$code, $sortie] = $this->garde([
            'app/Http/Controllers/API/Evaluation/EvaluationKlassciSyncController.php',
            'app/Services/Seances/SeanceVisioEnricher.php',
            'app/Services/Klassci/KlassciHttpClient.php',
        ]);

        $this->assertSame(0, $code, $sortie);
    }

    /**
     * @param  list<string>  $fichiers
     * @return array{0: int, 1: string}
     */
    private function garde(array $fichiers): array
    {
        $script = base_path('scripts/check-method-sizes.php');
        $arguments = implode(' ', array_map('escapeshellarg', $fichiers));

        $sortie = [];
        $code = 0;
        // Depuis la racine du projet : un chemin relatif s'y résout comme en CI.
        exec('cd '.escapeshellarg(base_path()).' && php '.escapeshellarg($script).' '.$arguments.' 2>&1', $sortie, $code);

        return [$code, implode("\n", $sortie)];
    }

    /**
     * Une méthode de `$lignes` lignes de code, dont la PREMIÈRE porte `$valeur` : une
     * interpolation placée en tête ferme le corps au plus tôt sous l'ancien comptage.
     */
    private function methode(string $nom, int $lignes, string $valeur): string
    {
        $corps = ['        $a = 0;', '        $s = '.$valeur.';'];
        for ($n = 2; $n < $lignes; $n++) {
            $corps[] = "        \$a += {$n};";
        }

        return "    public function {$nom}(): void\n    {\n".implode("\n", $corps)."\n    }\n";
    }

    /**
     * Un fichier de classe sous un chemin `app/` temporaire : le garde ne lit que `app/`.
     *
     * @param  list<string>  $methodes
     */
    private function classe(string $nom, array $methodes): string
    {
        $chemin = sys_get_temp_dir().'/msg_'.uniqid().'/app/'.$nom.'.php';
        @mkdir(dirname($chemin), 0777, true);
        file_put_contents($chemin, "<?php\n\nfinal class {$nom}\n{\n".implode("\n", $methodes)."}\n");

        return $chemin;
    }
}
