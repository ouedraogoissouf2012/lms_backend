<?php

declare(strict_types=1);

namespace Tests\Feature\Institution;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #794 — la mise à jour d'un établissement accepte une URL KLASSCI vide, comme
 * la création.
 *
 * L'écran d'administration envoie toujours la clé `klassci_api_url`
 * (`useAdminInstitutions.js`, `{ ...form.value }`) : `null` pour une école sans
 * URL, et `''` devient `null` (ConvertEmptyStringsToNull). La règle de mise à
 * jour doit donc l'accepter, sans cesser de refuser une URL invalide.
 *
 * Vider l'URL ne change ni le mode (sa bascule a sa route, #818) ni le jeton.
 * Compte supradmin plateforme, vrai jeton, les deux types d'école.
 */
final class MiseAJourDeLUrlKlassciTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const URL = 'https://ecole.klassci.test/api/lms';

    /** @return iterable<string, array{0: string}> */
    public static function volets(): iterable
    {
        yield 'ecole klassci' => ['klassci'];
        yield 'ecole autonome' => ['standalone'];
    }

    #[DataProvider('volets')]
    public function test_la_charge_exacte_du_front_met_a_jour_une_ecole_sans_url(string $volet): void
    {
        $ecole = $this->ecole($volet, null);

        // La charge de `saveInstitution()` en édition : le jeton vide est retiré,
        // `logo_url` vide devient null, `klassci_api_url` est la valeur de l'école.
        $this->modifier($ecole, [
            'slug' => $ecole->slug,
            'name' => 'Nouveau nom',
            'klassci_api_url' => null,
            'logo_url' => null,
            'primary_color' => '#3b82f6',
            'is_active' => true,
        ])->assertOk();

        self::assertSame('Nouveau nom', $this->ligne($ecole)['name']);
        self::assertNull($this->ligne($ecole)['klassci_api_url']);
    }

    #[DataProvider('volets')]
    public function test_l_url_se_retire(string $volet): void
    {
        foreach ([null, ''] as $vide) {
            $ecole = $this->ecole($volet, self::URL);

            $this->modifier($ecole, ['klassci_api_url' => $vide])->assertOk();

            self::assertNull($this->ligne($ecole)['klassci_api_url']);
        }
    }

    #[DataProvider('volets')]
    public function test_l_url_se_pose(string $volet): void
    {
        $ecole = $this->ecole($volet, null);

        $this->modifier($ecole, ['klassci_api_url' => self::URL])->assertOk();

        self::assertSame(self::URL, $this->ligne($ecole)['klassci_api_url']);
    }

    #[DataProvider('volets')]
    public function test_une_url_invalide_reste_refusee(string $volet): void
    {
        $ecole = $this->ecole($volet, self::URL);

        $this->modifier($ecole, ['klassci_api_url' => 'http://ecole.klassci.test/api/lms'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('klassci_api_url');

        self::assertSame(self::URL, $this->ligne($ecole)['klassci_api_url']);
    }

    #[DataProvider('volets')]
    public function test_vider_l_url_ne_touche_ni_le_mode_ni_le_jeton(string $volet): void
    {
        $ecole = $this->ecole($volet, self::URL);
        $avant = $this->ligne($ecole);

        $this->modifier($ecole, ['klassci_api_url' => null])->assertOk();

        $apres = $this->ligne($ecole);
        self::assertSame($avant['mode'], $apres['mode']);
        self::assertSame($avant['klassci_api_token_encrypted'], $apres['klassci_api_token_encrypted']);
    }

    /** @param  array<string, mixed>  $charge */
    private function modifier(Institution $ecole, array $charge): TestResponse
    {
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        return $this->asTenant($supradmin)->putJson("/api/admin/institutions/{$ecole->id}", $charge);
    }

    private function ecole(string $volet, ?string $url): Institution
    {
        $fabrique = $volet === 'standalone' ? Institution::factory()->standalone() : Institution::factory();

        return $fabrique->create(['klassci_api_url' => $url]);
    }

    /** @return array<string, mixed> */
    private function ligne(Institution $ecole): array
    {
        return (array) DB::table('institutions')->where('id', $ecole->id)->first();
    }
}
