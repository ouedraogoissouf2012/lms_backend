<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #919 — `ErrorResponse` décrit le corps que l'API émet réellement.
 *
 * Le contrat exigeait un `error_code` qu'aucune réponse ne porte : `success`,
 * `message`, et en option `errors` (422) ou `reason` (ADR-906-01), voilà tout
 * ce que posent `RespondsWithJson::errorResponse()` et les rendus de
 * `bootstrap/app.php`. ADR-919-01 retire `error_code` du contrat.
 *
 * Trois statuts typés `ErrorResponse`, chacun par sa réponse RÉELLE, validée
 * par le validateur de contrat du dépôt. Les volets d'école :
 * - le 401 précède toute école ;
 * - le 422 de validation précède la garde du catalogue : les deux volets ;
 * - le 404 « classe introuvable » vient APRÈS la garde du catalogue local,
 *   qu'une école KLASSCI ne franchit pas (403) : seul le volet autonome
 *   l'atteint.
 */
final class ErrorResponseCorpsReelTest extends TestCase
{
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    public function test_le_401_suit_error_response(): void
    {
        $reponse = $this->getJson('/api/classes')->assertStatus(401);

        $this->assertMatchesOpenApiResponse($reponse, '/classes', 'get');
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
    public function test_le_422_suit_error_response(InstitutionMode $mode): void
    {
        $reponse = $this->par($this->responsable($mode))
            ->postJson('/api/matieres', ['code' => 'SANS-LIBELLE'])
            ->assertStatus(422);

        $this->assertMatchesOpenApiResponse($reponse, '/matieres', 'post');
    }

    public function test_le_404_suit_error_response(): void
    {
        $reponse = $this->par($this->responsable(InstitutionMode::Standalone))
            ->postJson('/api/classes/999999/code-inscription')
            ->assertStatus(404);

        $this->assertMatchesOpenApiResponse($reponse, '/classes/{classe}/code-inscription', 'post');
    }

    /**
     * La garde : aucun schéma ne déclare `error_code`, et aucun exemple ne le
     * porte. Un champ que l'API n'émet pas ne revient pas dans le contrat.
     */
    public function test_le_contrat_ne_decrit_plus_error_code(): void
    {
        $spec = Yaml::parseFile(base_path('docs/openapi.yaml'));
        self::assertIsArray($spec);

        $traces = [];
        $this->chercher($spec, '', $traces);

        self::assertSame(
            [],
            $traces,
            "docs/openapi.yaml décrit encore `error_code`, que l'API n'émet jamais (ADR-919-01) :\n  - "
            .implode("\n  - ", $traces)
        );
    }

    /**
     * @param  list<string>  $traces
     */
    private function chercher(mixed $noeud, string $chemin, array &$traces): void
    {
        if (! is_array($noeud)) {
            return;
        }
        foreach ($noeud as $cle => $valeur) {
            $ici = $chemin.'/'.$cle;
            if ($cle === 'error_code') {
                $traces[] = $ici;
            }
            if ($cle === 'required' && is_array($valeur) && in_array('error_code', $valeur, true)) {
                $traces[] = $ici;
            }
            $this->chercher($valeur, $ici, $traces);
        }
    }

    private function responsable(InstitutionMode $mode): User
    {
        $factory = Institution::factory();
        $ecole = ($mode === InstitutionMode::Standalone ? $factory->standalone() : $factory)->create(['mode' => $mode]);

        return User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin']);
    }

    private function par(User $compte): static
    {
        return $this->withToken($compte->createToken('error-response-919')->plainTextToken);
    }
}
