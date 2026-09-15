<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Tests de synchronisation code ↔ OpenAPI (#213, cliquet #809).
 *
 * Objectif : empêcher la documentation de mentir, DANS LES DEUX SENS.
 *
 *   1. Tout endpoint documenté DOIT exister réellement en route (#213).
 *   2. Toute route réelle DOIT être documentée, ou figurer dans la dette
 *      nommée `openapi-coverage-baseline.php` (#809).
 *
 * ## Pourquoi le second invariant a été ajouté
 *
 * Le sens 2 n'était que *rapporté*, jamais gardé — le commentaire d'origine
 * l'assumait : « on n'échoue pas sur le gap ». Mesure du 2026-09-15 :
 * **27 routes documentées sur 174**. On pouvait donc livrer cinquante endpoints
 * sans une ligne de spec, CI verte.
 *
 * Le coût était réel et documenté côté front, sous l'étiquette `api-contract` :
 * une route qui existe et n'est jamais appelée (`frontend_lms#383`), deux boutons
 * d'export qui tapent dans une route morte (#337), une frontière violée (#329),
 * des désalignements de contrat (#240). Le front ne consomme par ailleurs ni la
 * spec ni le SDK : la spec était produite, validée, et lue par personne.
 *
 * ## Une liste nommée, pas un compteur
 *
 * Un plafond numérique laisserait supprimer une route non documentée pour en
 * ajouter une autre : total constant, dette déplacée, front toujours aveugle.
 * La baseline liste donc chaque chemin, comme `scripts/method-length-baseline.php`.
 *
 * ## Régénérer la baseline
 *
 *     OPENAPI_BASELINE_WRITE=1 php vendor/bin/phpunit --filter OpenApiSyncTest
 *
 * La génération se fait DEPUIS ce test, et non depuis un script autonome : la
 * table de routes dépend de variables que seul `phpunit.xml` pose
 * (`APP_ENV=testing`, `TELESCOPE_ENABLED=false`…). Un générateur externe voyait
 * 173 routes là où le test en voit 174 — une baseline inexacte aurait fait
 * rougir la CI pour une route pourtant préexistante.
 *
 * @see docs/openapi.yaml
 * @see scripts/openapi-validator.py
 */
final class OpenApiSyncTest extends TestCase
{
    private const SPEC_PATH = 'docs/openapi.yaml';

    private const BASELINE_PATH = __DIR__.'/openapi-coverage-baseline.php';

    /**
     * Ensemble des URIs de routes API réelles, normalisées sans le préfixe
     * `api/` ni `api/v1/`, avec un slash de tête (`/auth/login`).
     *
     * @return array<string, true>
     */
    private function realApiPaths(): array
    {
        $paths = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            // On ne garde que les routes API, et on déduplique v1/non-versionné.
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $normalized = preg_replace('#^api/(v1/|v2/)?#', '/', $uri);
            $paths[$normalized] = true;
        }

        return $paths;
    }

    /**
     * @return array<int, string> Paths déclarés dans la spec OpenAPI.
     */
    private function documentedPaths(): array
    {
        $spec = Yaml::parseFile(base_path(self::SPEC_PATH));

        $paths = $spec['paths'] ?? [];

        return is_array($paths) ? array_keys($paths) : [];
    }

    /**
     * Routes réelles absentes de la spec, triées.
     *
     * @return array<int, string>
     */
    private function undocumentedPaths(): array
    {
        $documented = array_flip($this->documentedPaths());

        $undocumented = array_values(array_filter(
            array_keys($this->realApiPaths()),
            static fn (string $path): bool => ! isset($documented[$path])
        ));

        sort($undocumented);

        return $undocumented;
    }

    /**
     * Dette tolérée, nommée.
     *
     * @return array<int, string>
     */
    private function baseline(): array
    {
        return is_file(self::BASELINE_PATH) ? (require self::BASELINE_PATH) : [];
    }

    public function test_openapi_spec_file_exists_and_is_valid_yaml(): void
    {
        $spec = Yaml::parseFile(base_path(self::SPEC_PATH));

        $this->assertIsArray($spec);
        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('openapi', $spec);
    }

    public function test_every_documented_endpoint_exists_as_real_route(): void
    {
        $real = $this->realApiPaths();
        $documented = $this->documentedPaths();

        $this->assertNotEmpty($documented, 'La spec OpenAPI ne déclare aucun path.');

        $orphans = [];
        foreach ($documented as $path) {
            if (! isset($real[$path])) {
                $orphans[] = $path;
            }
        }

        $this->assertSame(
            [],
            $orphans,
            'Endpoints documentés dans openapi.yaml mais ABSENTS des routes réelles '
            ."(doc obsolète, à corriger) :\n  - ".implode("\n  - ", $orphans)
        );
    }

    /**
     * Le cliquet (#809) : la dette non documentée ne peut plus grossir.
     */
    public function test_no_new_undocumented_route(): void
    {
        $undocumented = $this->undocumentedPaths();

        if (getenv('OPENAPI_BASELINE_WRITE') !== false) {
            $this->writeBaseline($undocumented);
            $this->markTestSkipped('Baseline régénérée : '.count($undocumented).' routes.');
        }

        $baseline = $this->baseline();

        // DÉNOMINATEUR (#701) : un cliquet qui ne dit pas ce qu'il a inspecté ne
        // distingue pas « rien à redire » de « je n'ai rien regardé ».
        $total = count($this->realApiPaths());
        $this->assertGreaterThan(100, $total, 'Table de routes anormalement courte : le cliquet ne prouverait rien.');

        $nouvelles = array_values(array_diff($undocumented, $baseline));

        $this->assertSame(
            [],
            $nouvelles,
            sprintf(
                "%d route(s) API ajoutée(s) sans entrée dans docs/openapi.yaml.\n"
                ."Le front ne peut pas les connaître : il ne consomme que la spec.\n"
                ."  - %s\n\n"
                ."→ Documente-les dans docs/openapi.yaml.\n"
                ."  Si la dette est vraiment assumée, régénère la baseline :\n"
                ."  OPENAPI_BASELINE_WRITE=1 php vendor/bin/phpunit --filter OpenApiSyncTest\n",
                count($nouvelles),
                implode("\n  - ", $nouvelles)
            )
        );
    }

    public function test_documentation_coverage_is_reported(): void
    {
        $total = count($this->realApiPaths());
        $undocumented = $this->undocumentedPaths();
        $covered = $total - count($undocumented);

        fwrite(STDERR, sprintf(
            "\n[OpenAPI coverage] %d/%d routes documentées (%.0f%%). Non documentées : %d\n",
            $covered,
            $total,
            $total > 0 ? ($covered / $total) * 100 : 0,
            count($undocumented),
        ));

        // Dette remboursée : on le signale, comme scripts/check-method-sizes.php.
        $reglees = array_values(array_diff($this->baseline(), $undocumented));
        if ($reglees !== []) {
            fwrite(STDOUT, sprintf(
                "\n✅ %d route(s) désormais documentée(s) — retire-les de la baseline :\n   - %s\n",
                count($reglees),
                implode("\n   - ", $reglees)
            ));
        }

        $this->assertGreaterThan(0, $covered, 'Au moins quelques routes doivent être documentées.');
    }

    /**
     * @param  array<int, string>  $undocumented
     */
    private function writeBaseline(array $undocumented): void
    {
        $lignes = array_map(
            static fn (string $c): string => "    '".str_replace("'", "\\'", $c)."',",
            $undocumented
        );

        $contenu = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * Dette tracée (#809) : routes API absentes de `docs/openapi.yaml`.
 *
 * Tolérée, mais elle ne peut plus GROSSIR : toute route qui n'est ni documentée
 * ni listée ici fait rougir le cliquet de OpenApiSyncTest (cité en prose et non
 * en {@see} : Pint transformerait la référence en import, et un fichier généré
 * doit sortir déjà conforme).
 *
 * Quand une route est documentée, RETIRER sa ligne — le test le rappelle.
 *
 * Régénérer :
 *     OPENAPI_BASELINE_WRITE=1 php vendor/bin/phpunit --filter OpenApiSyncTest
 */

return [
%s
];

PHP;

        file_put_contents(self::BASELINE_PATH, sprintf($contenu, implode("\n", $lignes)));
    }
}
