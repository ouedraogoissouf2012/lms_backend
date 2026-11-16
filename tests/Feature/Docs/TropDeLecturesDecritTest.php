<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1134 — le 429 des lectures authentifiées figure dans la spec.
 *
 * Depuis #947, toute lecture authentifiée a une limite de débit (le limiteur
 * `lecture`, posé sur le groupe `api`) : chacune peut rendre 429. Un client
 * généré ne lit que les réponses de chaque opération, donc chaque GET
 * authentifiée le décrit : par la réponse partagée `TooManyRequests`, ou, si
 * elle a déjà un 429 d'un autre sens, dans sa description.
 *
 * La seconde moitié prouve que `TooManyRequests` décrit le corps RÉEL : un
 * compte épuise son seau « suivi », et le 429 suivant est validé contre la
 * spec. La limite ne dépend pas du mode : les deux volets sont figés.
 */
final class TropDeLecturesDecritTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const REF = '#/components/responses/TooManyRequests';

    /** Le seuil du seau « suivi » (`LimiteDesLectures::SUIVI_PAR_MINUTE`, ADR-947-01). */
    private const SUIVI = 60;

    public function test_chaque_lecture_authentifiee_decrit_son_429(): void
    {
        $spec = Yaml::parseFile(base_path('docs/openapi.yaml'));
        self::assertIsArray($spec);
        $globale = $spec['security'] ?? [];
        $inspectees = 0;
        $manques = [];

        foreach ($spec['paths'] as $chemin => $item) {
            $operation = $item['get'] ?? null;
            if (! is_array($operation) || (array_key_exists('security', $operation) ? $operation['security'] : $globale) === []) {
                continue;
            }
            $inspectees++;
            $reponse = $operation['responses']['429'] ?? null;
            $description = (string) preg_replace('/\s+/u', ' ', (string) (is_array($reponse) ? ($reponse['description'] ?? '') : ''));

            if (! is_array($reponse) || (($reponse['$ref'] ?? null) !== self::REF && ! str_contains($description, 'limite de débit des lectures'))) {
                $manques[] = "GET $chemin";
            }
        }

        self::assertGreaterThan(40, $inspectees, 'La garde n\'a presque rien inspecté : elle ne prouverait rien.');
        self::assertSame(
            [],
            $manques,
            sprintf(
                "%d lecture(s) authentifiée(s) sans 429 décrit, sur %d :\n  - %s\n\n"
                ."→ python scripts/openapi-429-des-lectures.py docs/openapi.yaml\n",
                count($manques),
                $inspectees,
                implode("\n  - ", $manques)
            )
        );
    }

    /**
     * @return array<string, array{InstitutionMode}>
     */
    public static function volets(): array
    {
        return [
            'école KLASSCI' => [InstitutionMode::Klassci],
            'école autonome' => [InstitutionMode::Standalone],
        ];
    }

    #[DataProvider('volets')]
    public function test_le_429_reel_suit_too_many_requests(InstitutionMode $mode): void
    {
        $factory = Institution::factory();
        $ecole = ($mode === InstitutionMode::Standalone ? $factory->standalone() : $factory)->create(['mode' => $mode]);
        $compte = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'enseignant']);

        for ($i = 0; $i < self::SUIVI; $i++) {
            $this->asTenant($compte)->getJson('/api/notifications/unread-count')->assertOk();
        }

        $reponse = $this->asTenant($compte)->getJson('/api/notifications/unread-count')
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->assertMatchesOpenApiResponse($reponse, '/notifications/unread-count', 'get');
    }
}
