<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #1181, volet KLASSCI : l'établissement disparaît ENTRE la découverte des tenants
 * (qui ne lit que les institutions actives) et la synchronisation du compte.
 *
 * Avant, `KlassciUserSynchronizer::sync()` recevait une institution nulle et créait
 * le compte, ou le restaurait, sans établissement. Désormais la connexion est
 * refusée, 403 `no_institution`, et aucun compte n'est créé ni restauré.
 *
 * La disparition est provoquée PENDANT l'appel `/auth/login` à KLASSCI, comme elle
 * arriverait en production : la découverte l'a vu actif, la synchronisation non.
 */
final class EtablissementKlassciDisparuTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_URL = 'https://tenant1181.klassci.test/api/lms';

    private Institution $ecole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ecole = Institution::factory()->create([
            'slug' => 'tenant1181',
            'is_active' => true,
            'mode' => InstitutionMode::Klassci,
            'klassci_api_url' => self::TENANT_URL,
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function disparitions(): array
    {
        return ['désactivé' => ['desactive'], 'supprimé' => ['supprime']];
    }

    #[DataProvider('disparitions')]
    public function test_un_compte_nouveau_n_est_pas_cree_sans_son_ecole(string $disparition): void
    {
        $this->feindreKlassci($disparition);

        $this->connecter()
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('reason', 'no_institution');

        self::assertSame(0, User::withoutGlobalScopes()->withTrashed()->count(), 'Aucun compte créé.');
    }

    #[DataProvider('disparitions')]
    public function test_un_compte_supprime_n_est_pas_restaure_sans_son_ecole(string $disparition): void
    {
        $supprime = User::factory()->for($this->ecole)->create([
            'role' => 'enseignant',
            'klassci_id' => 11810,
            'email' => 'agent1181@klassci.test',
        ]);
        $supprime->delete();
        $this->feindreKlassci($disparition);

        $this->connecter()->assertForbidden()->assertJsonPath('reason', 'no_institution');

        $relu = User::withoutGlobalScopes()->withTrashed()->findOrFail($supprime->id);
        self::assertTrue($relu->trashed(), 'Le compte reste supprimé.');
        self::assertSame($this->ecole->id, $relu->institution_id);
    }

    /** Verrou : l'école toujours active, la connexion crée le compte dans son école. */
    public function test_temoin_l_ecole_active_cree_le_compte(): void
    {
        $this->feindreKlassci(null);

        $this->connecter()->assertOk();

        self::assertSame($this->ecole->id, User::withoutGlobalScopes()->where('klassci_id', 11810)->value('institution_id'));
    }

    private function feindreKlassci(?string $disparition): void
    {
        $ecole = $this->ecole;
        $utilisateur = ['id' => 11810, 'email' => 'agent1181@klassci.test', 'name' => 'Agent 1181', 'role' => 'enseignant'];

        Http::fake(static function (Request $requete) use ($ecole, $disparition, $utilisateur) {
            if (str_ends_with($requete->url(), '/auth/check-user')) {
                return Http::response(['success' => true, 'data' => ['found' => true]], 200);
            }
            if (str_ends_with($requete->url(), '/auth/login')) {
                // L'école disparaît pendant que KLASSCI authentifie le compte.
                match ($disparition) {
                    'desactive' => $ecole->forceFill(['is_active' => false])->save(),
                    'supprime' => $ecole->delete(),
                    default => null,
                };

                return Http::response(['success' => true, 'data' => ['user' => $utilisateur, 'token' => 'jeton-1181'], 'meta' => []], 200);
            }

            return Http::response(['success' => true, 'data' => []], 200);
        });
    }

    private function connecter(): TestResponse
    {
        return $this->postJson('/api/auth/login', ['username' => 'agent.1181', 'password' => 'mot-de-passe-1181']);
    }
}
