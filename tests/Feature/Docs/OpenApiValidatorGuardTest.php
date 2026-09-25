<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use Tests\TestCase;

/**
 * #891 — le validateur OpenAPI doit dire quand il n'a PAS pu travailler.
 *
 * ## Le défaut
 *
 * `scripts/openapi-validator.py:20-25` attrape l'absence de PyYAML, écrit un
 * avertissement sur la sortie STANDARD, et **sort 0**. Rien n'a été validé, et
 * la CI lit un succès.
 *
 * C'est précisément ce que `PRODUCTION_STANDARDS.md` §1.1-bis interdit (#701) :
 * un garde-fou distingue **trois** sorties — `0` conforme, `1` violation,
 * **`2` il n'a pas pu travailler**.
 *
 * ## Pourquoi le risque est latent et non actif
 *
 * La CI installe PyYAML (`security.yml:153-154`) avant d'appeler le validateur
 * (`:176`). Il suffit que cette étape soit retirée, renommée, ou qu'elle échoue
 * en silence, pour que l'étape de validation passe au vert sans rien inspecter.
 *
 * Ce test rend cette dépendance visible : il exécute le script **sans** PyYAML
 * et lit ce qu'il rend — code de sortie et flux.
 *
 * @see scripts/openapi-validator.py
 */
final class OpenApiValidatorGuardTest extends TestCase
{
    private string $bacASable = '';

    protected function tearDown(): void
    {
        if ($this->bacASable !== '' && is_dir($this->bacASable)) {
            @unlink($this->bacASable.'/yaml.py');
            @rmdir($this->bacASable);
        }

        parent::tearDown();
    }

    /**
     * Sans PyYAML, le script n'a rien validé : il doit le DIRE, pas mentir.
     *
     * Sortie 2 — « je n'ai pas pu travailler » — et le message sur stderr, là
     * où la CI le lit comme un échec, pas sur stdout où il se noie.
     */
    public function test_sans_pyyaml_le_validateur_annonce_qu_il_n_a_pas_travaille(): void
    {
        [$code, $sortie, $erreurs] = $this->executer(sansPyyaml: true);

        self::assertSame(2, $code, "sortie {$code} : le validateur annonce un succes sans avoir rien inspecte");
        self::assertStringContainsString('PyYAML', $erreurs, 'la cause doit etre dite sur stderr');
        self::assertStringNotContainsString('PyYAML', $sortie, 'un echec annonce sur stdout se noie dans le journal de CI');
    }

    /**
     * Avec PyYAML, le script affiche son DÉNOMINATEUR.
     *
     * « Aucune violation » ne prouve rien tant qu'on ignore combien d'éléments
     * ont été inspectés — §1.1-bis, même exigence que
     * `scripts/check-phpstan-baseline.php`.
     */
    public function test_le_validateur_publie_son_denominateur(): void
    {
        [$code, $sortie] = $this->executer(sansPyyaml: false);

        self::assertContains($code, [0, 1], "le validateur n a pas pu travailler (code {$code})");
        self::assertMatchesRegularExpression('/Paths:\s*\d+/', $sortie, 'le nombre de chemins inspectes doit etre publie');
        self::assertMatchesRegularExpression('/Endpoints:\s*\d+/', $sortie, 'le nombre d operations inspectees doit etre publie');
    }

    /**
     * Exécute le script réel et rend ce qu'il produit.
     *
     * Pour simuler l'absence de PyYAML sans toucher à l'environnement, un
     * module `yaml` factice qui lève `ImportError` est place EN TETE du chemin
     * de recherche Python. Le script prend alors sa branche d'erreur.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function executer(bool $sansPyyaml): array
    {
        $racine = base_path();
        // `PYTHONIOENCODING` : sur un poste Windows la console est en cp1252 et
        // les emojis du rapport levent `UnicodeEncodeError`. C'est un artefact
        // d'ENVIRONNEMENT, pas un defaut du script -- on le neutralise ici
        // plutot que d'amputer le rapport pour faire passer un test.
        // L'environnement est COMPLETE, jamais remplace : `proc_open` substitue
        // l'environnement entier des qu'on lui en passe un, et Python prive de
        // `PATH` et `SYSTEMROOT` ne retrouve plus ses paquets -- il sortait donc
        // 2 meme avec PyYAML installe. Mesure faite a l'ecriture de ce test.
        $env = getenv() + ['PYTHONIOENCODING' => 'utf-8'];

        if ($sansPyyaml) {
            $this->bacASable = sys_get_temp_dir().'/openapi-guard-'.bin2hex(random_bytes(6));
            mkdir($this->bacASable);
            file_put_contents($this->bacASable.'/yaml.py', "raise ImportError('absent pour ce test')\n");
            $env['PYTHONPATH'] = $this->bacASable;
        }

        $descripteurs = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $processus = proc_open(
            ['python', 'scripts/openapi-validator.py', 'docs/openapi.yaml'],
            $descripteurs,
            $tuyaux,
            $racine,
            $env,
        );

        if (! is_resource($processus)) {
            self::markTestSkipped('python indisponible dans cet environnement');
        }

        $sortie = (string) stream_get_contents($tuyaux[1]);
        $erreurs = (string) stream_get_contents($tuyaux[2]);
        fclose($tuyaux[1]);
        fclose($tuyaux[2]);

        return [proc_close($processus), $sortie, $erreurs];
    }
}
