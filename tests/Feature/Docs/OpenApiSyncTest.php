<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Tests de synchronisation code ↔ OpenAPI (#213).
 *
 * Objectif : empêcher la documentation de mentir. La spec `docs/openapi.yaml`
 * est PARTIELLE (elle ne couvre pas les ~177 routes) — on n'exige donc pas une
 * couverture totale, mais on garantit l'invariant qui apporte de la valeur :
 *
 *   **Tout endpoint documenté DOIT exister réellement en route.**
 *
 * Ainsi, supprimer/renommer une route sans toucher la doc fait échouer la CI
 * (détection de doc obsolète). Le gap de couverture (routes non documentées)
 * est exposé en information, sans bloquer — documenter 177 routes d'un coup
 * serait une fausse exigence.
 *
 * @see docs/openapi.yaml
 * @see scripts/openapi-validator.py
 */
final class OpenApiSyncTest extends TestCase
{
    private const SPEC_PATH = 'docs/openapi.yaml';

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
            "Endpoints documentés dans openapi.yaml mais ABSENTS des routes réelles "
            . "(doc obsolète, à corriger) :\n  - " . implode("\n  - ", $orphans)
        );
    }

    public function test_documentation_coverage_is_reported(): void
    {
        $real = $this->realApiPaths();
        $documented = array_flip($this->documentedPaths());

        $undocumented = array_filter(
            array_keys($real),
            static fn (string $path): bool => ! isset($documented[$path])
        );

        $total = count($real);
        $covered = $total - count($undocumented);

        // Informatif : on n'échoue pas sur le gap (doc volontairement partielle),
        // mais on l'expose pour piloter l'effort de documentation.
        fwrite(STDERR, sprintf(
            "\n[OpenAPI coverage] %d/%d routes documentées (%.0f%%). Non documentées : %d\n",
            $covered,
            $total,
            $total > 0 ? ($covered / $total) * 100 : 0,
            count($undocumented),
        ));

        $this->assertGreaterThan(0, $covered, 'Au moins quelques routes doivent être documentées.');
    }
}
