<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use DirectoryIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * La convention documentaire désigne la spec que le cliquet garde (#889).
 *
 * ## Le défaut
 *
 * `OpenApiSyncTest` (#809) ne lit que `docs/openapi.yaml`. Or cinq autres specs
 * coexistaient, et quinze fichiers prescrivaient d'éditer `docs/openapi-full.yaml`
 * puis de la copier sous `storage/api-docs/`. Qui suivait les guides à la lettre
 * documentait dans un fichier que rien ne lisait : sa route restait comptée en
 * dette, CI verte, sans que personne le lui dise.
 *
 * Swagger servait lui aussi une copie : juste en production, parce que
 * `docker/entrypoint.sh` l'écrasait au démarrage, fausse partout ailleurs.
 *
 * ## Trois invariants
 *
 *   1. le dépôt ne contient qu'UNE spec OpenAPI ;
 *   2. aucun fichier ne désigne une autre spec que celle-là ;
 *   3. Swagger sert cette spec, octet pour octet, sans copie intermédiaire.
 *
 * Les deux premiers se jugent au contenu, pas à une liste de noms interdits :
 * une `docs/openapi-v2.yaml` créée demain rougit comme `openapi-full.yaml` hier.
 *
 * Le deuxième tolère UNE exception, les répertoires dont la fonction est de
 * raconter le passé : un ADR ou une note archivée doit pouvoir nommer le fichier
 * qu'on a supprimé. Le premier n'en tolère aucune — pas même une spec archivée.
 *
 * @see tests/Feature/Docs/OpenApiSyncTest.php
 */
final class OpenApiConventionTest extends TestCase
{
    private const SPEC = 'docs/openapi.yaml';

    /**
     * Inspectés récursivement. `vendor/`, `node_modules/` et les données
     * d'exécution n'en font pas partie ; les fichiers de la racine sont
     * inspectés à part, sans descendre (la racine peut héberger des worktrees).
     */
    private const ROOTS = [
        '.github', 'app', 'config', 'database', 'docker', 'docs', 'public',
        'resources', 'routes', 'scripts', 'storage/api-docs', 'tests',
    ];

    /** Mémoire du dépôt : ils nomment le passé, ils ne prescrivent rien. */
    private const HISTORY_DIRS = ['docs/adr/', 'docs/archive/'];

    private const TEXT_EXTENSIONS = [
        'conf', 'ini', 'json', 'md', 'neon', 'php', 'py', 'sh', 'txt', 'xml', 'yaml', 'yml',
    ];

    /** Un document OpenAPI ou Swagger s'annonce par sa première clé. */
    private const SPEC_SIGNATURE = '/^\s*["\']?(openapi|swagger)["\']?\s*:/m';

    public function test_the_repository_holds_a_single_openapi_spec(): void
    {
        $files = $this->inspectedFiles();
        $specs = array_keys(array_filter($files, fn (string $path): bool => $this->isSpec($path)));

        $this->reportDenominator('specs', count($files));

        $this->assertSame(
            [self::SPEC],
            $specs,
            "Une seule spec OpenAPI : celle que garde OpenApiSyncTest.\n"
            .'Toute autre est une copie qui dérivera sans que la CI le voie.'
        );
    }

    public function test_no_file_designates_another_spec(): void
    {
        $files = $this->inspectedFiles();
        $violations = [];

        foreach ($files as $relative => $path) {
            if ($path === __FILE__ || $this->isHistory($relative)) {
                continue; // ce test nomme les motifs qu'il interdit ; l'histoire nomme le passé
            }
            foreach ($this->foreignSpecReferences((string) file_get_contents($path)) as $reference) {
                $violations[] = "{$relative} → {$reference}";
            }
        }

        $this->reportDenominator('références', count($files));

        $this->assertSame(
            [],
            $violations,
            "Ces fichiers désignent une spec que le cliquet ne lit pas.\n"
            ."→ La seule spec est docs/openapi.yaml.\n  - ".implode("\n  - ", $violations)
        );
    }

    public function test_swagger_serves_the_guarded_spec(): void
    {
        $response = $this->get(route('l5-swagger.default.docs'));

        $response->assertOk();
        $this->assertSame(
            file_get_contents(base_path(self::SPEC)),
            $response->getContent(),
            'Swagger UI doit servir docs/openapi.yaml, pas une copie.'
        );
    }

    /**
     * Fichiers texte inspectés, indexés par chemin relatif (séparateur `/`).
     *
     * @return array<string, string>
     */
    private function inspectedFiles(): array
    {
        $files = [];

        foreach (new DirectoryIterator(base_path()) as $entry) {
            if ($entry->isFile() && $this->isText($entry)) {
                $files[$entry->getFilename()] = $entry->getPathname();
            }
        }

        foreach (self::ROOTS as $root) {
            $absolute = base_path($root);
            if (! is_dir($absolute)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $entry) {
                if ($entry instanceof SplFileInfo && $entry->isFile() && $this->isText($entry)) {
                    $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen(base_path()) + 1));
                    $files[$relative] = $entry->getPathname();
                }
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * Une copie de sauvegarde (`openapi.yaml.backup`) garde le nom de sa source
     * dans son propre nom : l'extension seule la laisserait passer.
     */
    private function isText(SplFileInfo $file): bool
    {
        return in_array(strtolower($file->getExtension()), self::TEXT_EXTENSIONS, true)
            || str_contains(strtolower($file->getFilename()), 'openapi')
            || str_starts_with($file->getFilename(), 'Dockerfile');
    }

    private function isHistory(string $relative): bool
    {
        foreach (self::HISTORY_DIRS as $directory) {
            if (str_starts_with($relative, $directory)) {
                return true;
            }
        }

        return false;
    }

    private function isSpec(string $path): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 512);

        return ! str_ends_with($path, '.php') && preg_match(self::SPEC_SIGNATURE, $head) === 1;
    }

    /**
     * Toute désignation d'une spec autre que `openapi.yaml` : un autre fichier
     * `openapi*.yaml|yml|json` (suffixe de sauvegarde compris), ou l'ancien
     * répertoire servi par Swagger.
     *
     * Le motif porte sur le NOM, pas sur le chemin : un guide rangé dans docs/
     * écrit `openapi-full.yaml` sans préfixe, et un motif `docs/…` le laissait
     * passer. Il exige une extension, pour ne pas prendre l'outil
     * `openapi-generator-cli` pour une spec.
     *
     * @return array<int, string>
     */
    private function foreignSpecReferences(string $content): array
    {
        preg_match_all('#\bopenapi(?:[-_][\w-]*)?\.(?:ya?ml|json)(?:\.\w+)?|api-docs/[\w.-]*#', $content, $matches);

        return array_values(array_unique(array_filter(
            $matches[0],
            static fn (string $match): bool => $match !== basename(self::SPEC)
        )));
    }

    private function reportDenominator(string $what, int $count): void
    {
        // DÉNOMINATEUR (#701) : sans lui, « rien trouvé » et « rien regardé » se confondent.
        fwrite(STDERR, sprintf("\n[OpenAPI convention] %s : %d fichiers inspectés\n", $what, $count));
        $this->assertGreaterThan(500, $count, 'Parcours anormalement court : la garde ne prouverait rien.');
    }
}
