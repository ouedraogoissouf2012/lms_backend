<?php

declare(strict_types=1);

namespace Tests\Unit\Support\OpenApi;

use PHPUnit\Framework\TestCase;
use Tests\Support\OpenApi\OpenApiSchemaValidator;

/**
 * #1356 — `docs/openapi.yaml` n'est analysé qu'une fois pour toute la suite.
 *
 * Chaque classe de contrat gardait sa propre copie (statique de trait) : 46 specs
 * analysées retenues jusqu'à la fin, et la suite touchait la limite de mémoire. Le
 * validateur est mémorisé par fichier ; un fichier réécrit en donne un nouveau.
 */
final class SpecAnalyseeUneFoisTest extends TestCase
{
    /** @var list<string> */
    private array $fichiers = [];

    protected function tearDown(): void
    {
        foreach ($this->fichiers as $fichier) {
            if (is_file($fichier)) {
                unlink($fichier);
            }
        }
        parent::tearDown();
    }

    public function test_le_meme_fichier_n_est_analyse_qu_une_fois(): void
    {
        $fichier = $this->spec('/premier');

        self::assertSame(OpenApiSchemaValidator::depuisFichier($fichier), OpenApiSchemaValidator::depuisFichier($fichier));
    }

    /** Verrou : un fichier réécrit au même chemin est relu, jamais servi périmé. */
    public function test_un_fichier_reecrit_est_relu(): void
    {
        $fichier = $this->spec('/avant');
        $avant = OpenApiSchemaValidator::depuisFichier($fichier);

        file_put_contents($fichier, self::contenu('/apres'));
        $apres = OpenApiSchemaValidator::depuisFichier($fichier);

        self::assertNotSame($avant, $apres);
        self::assertNotNull($apres->schemaDeReponse('/apres', 'GET', 200));
        self::assertNull($apres->schemaDeReponse('/avant', 'GET', 200));
    }

    /** Verrou : le même contenu à un autre chemin est un autre fichier. */
    public function test_un_autre_chemin_donne_un_autre_validateur(): void
    {
        self::assertNotSame(
            OpenApiSchemaValidator::depuisFichier($this->spec('/commun')),
            OpenApiSchemaValidator::depuisFichier($this->spec('/commun')),
        );
    }

    private function spec(string $route): string
    {
        $fichier = tempnam(sys_get_temp_dir(), 'spec1356');
        self::assertIsString($fichier);
        file_put_contents($fichier, self::contenu($route));
        $this->fichiers[] = $fichier;

        return $fichier;
    }

    private static function contenu(string $route): string
    {
        return <<<YAML
            openapi: 3.0.3
            paths:
              {$route}:
                get:
                  responses:
                    '200':
                      description: ok
                      content:
                        application/json:
                          schema:
                            type: object
            YAML;
    }
}
