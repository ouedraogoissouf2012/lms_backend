<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Garde structurel : aucun contrôleur ne relaie une enveloppe de service à la
 * main (#693).
 *
 * ## Pourquoi cette garde EST le lot, et pas un supplément
 *
 * La migration de #693 ne fait gagner aucune ligne — elle remplace une ligne par
 * une ligne. Son seul gain mesurable est d'amener `JsonPayloadGuard` sur un
 * chemin qui en était dépourvu : 47 sites rendaient un payload sans jamais
 * vérifier qu'il est sérialisable.
 *
 * Or une couverture qui n'est pas verrouillée n'est pas un acquis. La revue l'a
 * démontré en l'exécutant : un 48ᵉ site écrit à l'ancienne dans un contrôleur
 * déjà migré laissait `check-ocp.php`, les deux gardes de taille, PHPStan
 * niveau 9 et toute la suite d'architecture **au vert**.
 *
 * Le typage `array{status:int, payload:array}` ne garde que les appels *à*
 * `relayResponse()`. Il est sans effet sur le site qui ne l'appelle jamais.
 *
 * Sans ce fichier, le bénéfice de #693 se serait érodé à la première PR suivante,
 * et personne ne l'aurait vu.
 *
 * ## Ce qu'elle vérifie
 *
 * Aucun fichier de `app/Http/Controllers/` ne contient
 * `response()->json($x['payload'], $x['status'])`. Après migration, ce motif ne
 * subsiste que dans le trait lui-même — qui est l'implémentation, donc exempté
 * nommément.
 *
 * Le dénominateur est publié : « rien à redire » et « je n'ai rien regardé » ne
 * doivent jamais produire le même message. C'est la leçon de
 * `check-file-sizes.php`, qui affichait « ✓ » sans avoir rien inspecté.
 *
 * @see app/Http/Controllers/Concerns/RespondsWithJson.php
 * @see tests/Feature/Architecture/NoOrphanSyncMethodTest.php  (le patron)
 */
final class NoRawEnvelopeRelayTest extends TestCase
{
    /** Le motif recopié 47 fois, dans sa forme exacte. */
    private const RELAIS_BRUT = '/response\(\)->json\(\s*\$\w+\[[\'"]payload[\'"]\]\s*,\s*\$\w+\[[\'"]status[\'"]\]\s*\)/';

    /**
     * Le trait PORTE l'implémentation : c'est le seul endroit légitime.
     * Exempté nommément, jamais par motif — une exemption large finirait par
     * couvrir ce qu'elle ne devait pas.
     */
    private const IMPLEMENTATION = 'Concerns/RespondsWithJson.php';

    public function test_aucun_controleur_ne_relaie_une_enveloppe_a_la_main(): void
    {
        $coupables = [];
        $inspectes = 0;

        foreach ($this->fichiersDeControleur() as $chemin) {
            $relatif = str_replace('\\', '/', $chemin);

            if (str_ends_with($relatif, self::IMPLEMENTATION)) {
                continue;
            }

            $inspectes++;

            if (preg_match(self::RELAIS_BRUT, (string) file_get_contents($chemin)) === 1) {
                $coupables[] = basename($chemin);
            }
        }

        // Le dénominateur AVANT l'assertion : si l'inspection ne trouve aucun
        // fichier, la garde doit échouer, pas se taire.
        $this->assertGreaterThan(
            30,
            $inspectes,
            "La garde n'a inspecté que {$inspectes} contrôleur(s) : elle n'a rien regardé."
        );

        $this->assertSame(
            [],
            $coupables,
            "Ces contrôleurs relaient une enveloppe à la main au lieu d'appeler "
            ."\$this->relayResponse(\$result) :\n  - ".implode("\n  - ", $coupables)
            ."\n\nLe relais direct contourne JsonPayloadGuard : une Closure dans le "
            ."payload serait encodée en {} par json_encode, produisant un 200 avec "
            ."la donnée disparue et aucun signal d'échec (#360, #693)."
        );
    }

    /**
     * @return list<string>
     */
    private function fichiersDeControleur(): array
    {
        $chemins = [];
        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Http/Controllers'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterateur as $fichier) {
            if ($fichier instanceof \SplFileInfo && $fichier->getExtension() === 'php') {
                $chemins[] = $fichier->getPathname();
            }
        }

        sort($chemins);

        return $chemins;
    }
}
