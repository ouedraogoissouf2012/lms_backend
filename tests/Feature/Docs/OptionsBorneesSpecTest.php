<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use Symfony\Component\Yaml\Yaml;
use Tests\Support\OpenApi\OpenApiSchemaValidator;
use Tests\TestCase;

/**
 * La spec borne les options d'une question comme le serveur (B5) : 26 options au plus
 * (`maxItems`), 1 000 caractères au plus par option (`maxLength`), dans le corps de
 * `PUT /evaluations/{id}`.
 *
 * Le corps de requête n'est validé par aucun test de réponse : ce test le lit lui-même,
 * avec le validateur du dépôt, qui doit donc savoir lire `maxItems`.
 */
final class OptionsBorneesSpecTest extends TestCase
{
    public function test_la_spec_accepte_vingt_six_options_de_mille_caracteres(): void
    {
        $options = array_map(static fn (int $i): string => str_pad((string) $i, 1000, 'o'), range(1, 26));

        self::assertSame([], $this->validateur()->erreurs($options, $this->schemaDesOptions()));
    }

    public function test_la_spec_refuse_vingt_sept_options(): void
    {
        $erreurs = $this->validateur()->erreurs(array_fill(0, 27, 'x'), $this->schemaDesOptions());

        self::assertCount(1, $erreurs, implode(' | ', $erreurs));
        self::assertStringContainsString('maxItems 26', $erreurs[0]);
    }

    public function test_la_spec_refuse_une_option_de_mille_un_caracteres(): void
    {
        $erreurs = $this->validateur()->erreurs([str_repeat('a', 1001)], $this->schemaDesOptions());

        self::assertCount(1, $erreurs, implode(' | ', $erreurs));
        self::assertStringContainsString('maxLength 1000', $erreurs[0]);
    }

    private function validateur(): OpenApiSchemaValidator
    {
        return OpenApiSchemaValidator::depuisFichier(base_path('docs/openapi.yaml'));
    }

    /** @return array<mixed> */
    private function schemaDesOptions(): array
    {
        $spec = Yaml::parseFile(base_path('docs/openapi.yaml'));
        $schema = $spec['paths']['/evaluations/{id}']['put']['requestBody']['content']['application/json']['schema']
            ['properties']['questions']['items']['properties']['options'] ?? null;
        self::assertIsArray($schema, 'le corps de PUT /evaluations/{id} décrit options');

        return $schema;
    }
}
