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
 * #961 — les deux refus de `ResolveInstitution` figurent dans la spec.
 *
 * Le middleware est global au groupe `api` : il refuse AVANT toute route.
 * - 403 : requête avec jeton, établissement du compte désactivé ou supprimé ;
 * - 400 : requête sans jeton, en-tête `X-Institution` inconnu ou inactif, rendu
 *   avant le 401 de l'authentification (mesuré en production par #961).
 *
 * OpenAPI 3 n'a pas de réponse globale, et un client généré ne lit que les
 * réponses de chaque opération : chacune doit donc les décrire, par la réponse
 * partagée ou, si le code y a déjà un autre sens, dans sa description.
 *
 * La seconde moitié prouve que l'exemple de la réponse partagée EST le corps
 * réel : une spec juste dans sa forme mais fausse dans son contenu ne vaudrait
 * rien. Les deux volets d'école : le middleware ne lit pas le mode, et le test
 * le fige.
 */
final class RefusDeLaResolutionDuTenantTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const METHODES = ['get', 'put', 'post', 'delete', 'patch'];

    private const REF_403 = '#/components/responses/EtablissementDesactive';

    private const REF_400 = '#/components/responses/EtablissementInconnu';

    /** Une route authentifiée et documentée : celle que #961 a mesurée en production. */
    private const ROUTE = '/api/lessons/my-courses';

    private const CHEMIN = '/lessons/my-courses';

    private const SLUG_INCONNU = 'slug-inexistant';

    public function test_chaque_operation_decrit_les_refus_de_la_resolution(): void
    {
        $spec = $this->spec();
        $globale = $spec['security'] ?? [];
        $inspectees = 0;
        $manques = [];

        foreach ($spec['paths'] as $chemin => $item) {
            foreach (self::METHODES as $methode) {
                if (! isset($item[$methode])) {
                    continue;
                }
                $inspectees++;
                $operation = $item[$methode];
                $authentifiee = (array_key_exists('security', $operation) ? $operation['security'] : $globale) !== [];
                $nom = strtoupper($methode).' '.$chemin;

                if ($authentifiee && ! self::decrit($operation['responses']['403'] ?? null, self::REF_403, '/[ée]tablissement.{0,3}d[ée]sactiv/iu')) {
                    $manques[] = "403 $nom";
                }
                if (! self::decrit($operation['responses']['400'] ?? null, self::REF_400, '/X-Institution/')) {
                    $manques[] = "400 $nom";
                }
            }
        }

        self::assertGreaterThan(90, $inspectees, 'La garde n\'a presque rien inspecté : elle ne prouverait rien.');
        self::assertSame(
            [],
            $manques,
            sprintf(
                "%d refus de ResolveInstitution absents de docs/openapi.yaml, sur %d opérations :\n  - %s\n\n"
                ."→ python scripts/openapi-refus-de-la-resolution.py docs/openapi.yaml\n",
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
    public function test_un_jeton_d_une_ecole_desactivee_recoit_le_corps_decrit(InstitutionMode $mode): void
    {
        $compte = User::factory()->create([
            'institution_id' => $this->ecole($mode, active: false)->id,
            'role' => 'enseignant',
        ]);

        $reponse = $this->asTenant($compte)->getJson(self::ROUTE)->assertStatus(403);

        $reponse->assertExactJson($this->exemple('EtablissementDesactive'));
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
    }

    public function test_un_slug_inconnu_recoit_le_400_avant_le_401(): void
    {
        $reponse = $this->getJson(self::ROUTE, ['X-Institution' => self::SLUG_INCONNU])->assertStatus(400);

        $reponse->assertExactJson($this->exemple('EtablissementInconnu'));
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
    }

    #[DataProvider('volets')]
    public function test_le_slug_d_une_ecole_inactive_recoit_le_meme_400(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode, active: false);

        $reponse = $this->getJson(self::ROUTE, ['X-Institution' => $ecole->slug])->assertStatus(400);

        $attendu = $this->exemple('EtablissementInconnu');
        $attendu['message'] = str_replace(self::SLUG_INCONNU, $ecole->slug, (string) $attendu['message']);
        $reponse->assertExactJson($attendu);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
    }

    private static function decrit(mixed $reponse, string $ref, string $motif): bool
    {
        if (! is_array($reponse)) {
            return false;
        }

        // Espaces normalisés : une description en bloc coupe ses phrases par un
        // saut de ligne indenté, qui sépare « établissement » de « désactivé ».
        $description = (string) preg_replace('/\s+/u', ' ', (string) ($reponse['description'] ?? ''));

        return ($reponse['$ref'] ?? null) === $ref || preg_match($motif, $description) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function exemple(string $reponsePartagee): array
    {
        $exemple = $this->spec()['components']['responses'][$reponsePartagee]['content']['application/json']['example'] ?? null;
        self::assertIsArray($exemple, "components.responses.$reponsePartagee : aucun exemple dans docs/openapi.yaml");

        return $exemple;
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        $spec = Yaml::parseFile(base_path('docs/openapi.yaml'));
        self::assertIsArray($spec);

        return $spec;
    }

    private function ecole(InstitutionMode $mode, bool $active): Institution
    {
        $factory = Institution::factory();

        return ($mode === InstitutionMode::Standalone ? $factory->standalone() : $factory)
            ->create(['mode' => $mode, 'is_active' => $active]);
    }
}
