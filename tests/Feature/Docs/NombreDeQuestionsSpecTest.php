<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use Symfony\Component\Yaml\Yaml;
use Tests\Support\OpenApi\OpenApiSchemaValidator;
use Tests\TestCase;

/**
 * La spec borne le nombre de questions comme le serveur (B5, partie 2) : 200 au plus
 * (`maxItems`), dans le corps de `PUT /evaluations/{id}`. Même lecture que
 * `OptionsBorneesSpecTest`, avec le validateur du dépôt.
 */
final class NombreDeQuestionsSpecTest extends TestCase
{
    public function test_la_spec_accepte_deux_cents_questions(): void
    {
        self::assertSame([], $this->validateur()->erreurs($this->questions(200), $this->schemaDesQuestions()));
    }

    public function test_la_spec_refuse_deux_cent_une_questions(): void
    {
        $erreurs = $this->validateur()->erreurs($this->questions(201), $this->schemaDesQuestions());

        self::assertCount(1, $erreurs, implode(' | ', $erreurs));
        self::assertStringContainsString('maxItems 200', $erreurs[0]);
    }

    /** @return list<array<string, mixed>> */
    private function questions(int $nombre): array
    {
        return array_fill(0, $nombre, ['question' => 'Combien font 2 + 2 ?', 'type' => 'qcm', 'options' => ['3', '4'], 'correct_answers' => ['4']]);
    }

    private function validateur(): OpenApiSchemaValidator
    {
        return OpenApiSchemaValidator::depuisFichier(base_path('docs/openapi.yaml'));
    }

    /** @return array<mixed> */
    private function schemaDesQuestions(): array
    {
        $spec = Yaml::parseFile(base_path('docs/openapi.yaml'));
        $corps = $spec['paths']['/evaluations/{id}']['put']['requestBody']['content']['application/json']['schema'] ?? null;
        $schema = is_array($corps) ? ($corps['properties']['questions'] ?? null) : null;
        self::assertIsArray($schema, 'le corps de PUT /evaluations/{id} décrit questions');

        return $schema;
    }
}
