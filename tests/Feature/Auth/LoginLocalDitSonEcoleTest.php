<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #795 — un login par la voie locale dit au client l'école du compte.
 *
 * `meta.institution` est le slug de l'école (la valeur que la voie KLASSCI y met
 * déjà, `institutions.slug`) et `meta.institution_name` son nom, lu sur la fiche
 * de l'école. Le client en fait son slug de travail et le nom affiché.
 *
 * Le compte sans école (supradmin) garde les deux clés, nulles. Par la vraie
 * route de login, les deux types d'école.
 */
final class LoginLocalDitSonEcoleTest extends TestCase
{
    use RefreshDatabase;

    private const MOT_DE_PASSE = 'Motdepasse1!';

    /** @return iterable<string, array{0: string}> */
    public static function volets(): iterable
    {
        yield 'ecole klassci' => ['klassci'];
        yield 'ecole autonome' => ['standalone'];
    }

    #[DataProvider('volets')]
    public function test_un_compte_local_recoit_le_slug_et_le_nom_de_son_ecole(string $volet): void
    {
        $ecole = $this->ecole($volet);
        $compte = $this->compte($ecole);

        $this->connecter($compte)
            ->assertJsonPath('meta.klassci_synced', false)
            ->assertJsonPath('meta.institution', $ecole->slug)
            ->assertJsonPath('meta.institution_name', $ecole->name);
    }

    #[DataProvider('volets')]
    public function test_chaque_compte_recoit_sa_propre_ecole(string $volet): void
    {
        $ici = $this->ecole($volet);
        $ailleurs = $this->ecole($volet);

        foreach ([$ici, $ailleurs] as $ecole) {
            $this->connecter($this->compte($ecole))
                ->assertJsonPath('meta.institution', $ecole->slug)
                ->assertJsonPath('meta.institution_name', $ecole->name);
        }
    }

    public function test_un_compte_lie_a_klassci_qui_retombe_sur_la_voie_locale_recoit_son_ecole(): void
    {
        // Une seule closure : KLASSCI refuse le renouvellement, le login retombe
        // sur la voie locale.
        Http::fake(static fn () => Http::response(['success' => false], 503));
        $ecole = $this->ecole('klassci');
        $compte = $this->compte($ecole, ['klassci_id' => 4242]);

        $this->connecter($compte)
            ->assertJsonPath('meta.klassci_synced', false)
            ->assertJsonPath('meta.institution', $ecole->slug)
            ->assertJsonPath('meta.institution_name', $ecole->name);
    }

    public function test_le_compte_sans_ecole_garde_les_deux_cles_nulles(): void
    {
        $supradmin = User::factory()->create([
            'role' => 'supradmin',
            'institution_id' => null,
            'password' => Hash::make(self::MOT_DE_PASSE),
        ]);

        $meta = $this->connecter($supradmin)->json('meta');

        self::assertIsArray($meta);
        self::assertArrayHasKey('institution', $meta);
        self::assertArrayHasKey('institution_name', $meta);
        self::assertNull($meta['institution']);
        self::assertNull($meta['institution_name']);
    }

    private function connecter(User $compte): TestResponse
    {
        return $this->postJson('/api/auth/login', [
            'username' => $compte->email,
            'password' => self::MOT_DE_PASSE,
        ])->assertOk()->assertJsonPath('success', true);
    }

    private function ecole(string $volet): Institution
    {
        return $volet === 'standalone'
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create();
    }

    /** @param  array<string, mixed>  $attributs */
    private function compte(Institution $ecole, array $attributs = []): User
    {
        return User::factory()->for($ecole)->teacher()->create([
            'password' => Hash::make(self::MOT_DE_PASSE),
            'klassci_id' => null,
            ...$attributs,
        ]);
    }
}
