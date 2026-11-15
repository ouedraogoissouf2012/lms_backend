<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Cache\RedisStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * `GET auth/me` de KLASSCI rend l'utilisateur DANS `data` : c'est la forme
 * documentée (`docs/INTEGRATION_KLASSCI.md`, « GET /auth/me ») et la forme
 * relevée dans le cache de développement (clés seules). Les deux lecteurs du
 * LMS lisaient `data.user`, la forme de `auth/login` : la re-synchronisation
 * n'écrivait jamais `last_klassci_sync`, et le profil « live » restait vide.
 *
 * Le compte « périmé » repassait donc par `auth/me` à chaque expiration du
 * cache par jeton (300 s), au lieu d'une fois par 24 h.
 *
 * Seule la frontière réseau est feinte (une closure par requête) ; le proxy,
 * son cache et le middleware sont réels, derrière un vrai jeton porteur.
 */
final class AuthMeFormeReelleTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const TENANT_URL = 'https://klassci.tenant.test';

    /** Une route du groupe `klassci.sync` qui n'appelle pas KLASSCI elle-même. */
    private const ROUTE_SYNCHRONISEE = '/api/chapters/999999';

    private int $appelsAuthMe = 0;

    public function test_la_forme_reelle_met_a_jour_le_compte_perime(): void
    {
        $compte = $this->comptePerime();
        $this->klassciRepond(fn () => $this->formeReelle($compte, nom: 'Kouassi', role: 'enseignant'));

        $this->asTenant($compte)->getJson(self::ROUTE_SYNCHRONISEE);

        $compte->refresh();
        self::assertSame('Kouassi', $compte->name);
        self::assertSame('enseignant', $compte->klassci_role);
        self::assertNotNull($compte->last_klassci_sync);
        self::assertTrue($compte->isKlassciDataFresh(), 'La re-synchronisation doit dater le compte.');
    }

    public function test_un_compte_resynchronise_n_appelle_plus_auth_me_apres_le_cache(): void
    {
        // Sous redis, le store expire sur l'horloge réelle, pas sur `travel()` : la
        // seconde requête lirait le cache, et le compte d'appels ne prouverait rien.
        if (app('cache')->store()->getStore() instanceof RedisStore) {
            self::markTestSkipped('TTL redis suit l horloge réelle, pas le time-travel Carbon.');
        }

        $compte = $this->comptePerime();
        $this->klassciRepond(fn () => $this->formeReelle($compte));

        $this->asTenant($compte)->getJson(self::ROUTE_SYNCHRONISEE);

        // Au-delà du cache par jeton (300 s) : seule la fraîcheur du compte évite l'appel.
        $this->travel(6)->minutes();
        $this->app->forgetScopedInstances();

        $this->asTenant($compte)->getJson(self::ROUTE_SYNCHRONISEE);

        self::assertSame(1, $this->appelsAuthMe, 'Un compte re-synchronisé ne repasse pas par auth/me avant 24 h.');
    }

    public function test_le_profil_live_est_lu_sur_la_forme_reelle(): void
    {
        $compte = $this->compte(['last_klassci_sync' => now()]);
        $this->klassciRepond(fn () => $this->formeReelle($compte, nom: 'Kouassi', role: 'enseignant'));

        $reponse = $this->asTenant($compte)->getJson('/api/auth/me');

        $reponse->assertOk();
        self::assertSame('Kouassi', $reponse->json('data.klassci_data.nom'));
        self::assertSame('enseignant', $reponse->json('data.klassci_data.role'));
        self::assertSame($compte->klassci_id, $reponse->json('data.klassci_data.id'));
        // La liste blanche s'applique toujours : `email` n'est pas une clé d'affichage.
        self::assertNull($reponse->json('data.klassci_data.email'));
    }

    /**
     * @param  \Closure(User): array<string, mixed>  $corps
     */
    #[DataProvider('reponsesIllisibles')]
    public function test_une_reponse_illisible_ne_met_rien_a_jour_et_avertit(\Closure $corps): void
    {
        $compte = $this->comptePerime();
        $nomAvant = $compte->name;
        $this->klassciRepond(fn () => $corps($compte));
        Log::spy();

        $this->asTenant($compte)->getJson(self::ROUTE_SYNCHRONISEE);

        $compte->refresh();
        self::assertSame($nomAvant, $compte->name);
        self::assertFalse($compte->isKlassciDataFresh(), 'Rien n\'est daté sur une réponse illisible.');
        Log::shouldHaveReceived('warning')
            ->with('klassci_auth_me_illisible', Mockery::on(
                fn (mixed $contexte): bool => $contexte === [
                    'user_id' => $compte->id,
                    'institution_id' => $compte->institution_id,
                ],
            ))
            ->once();
    }

    /** @return array<string, array{0: \Closure(User): array<string, mixed>}> */
    public static function reponsesIllisibles(): array
    {
        return [
            // La forme de `auth/login`, jamais relevée pour `auth/me` : elle n'est plus lue.
            'utilisateur sous data.user' => [
                fn (User $compte): array => ['success' => true, 'data' => ['user' => ['id' => $compte->klassci_id, 'nom' => 'X']]],
            ],
            'data sans identifiant' => [fn (User $compte): array => ['success' => true, 'data' => ['nom' => 'X']]],
            // Relevé : un entier. Une autre forme n'a jamais été vue.
            'identifiant non entier' => [fn (User $compte): array => ['success' => true, 'data' => ['id' => '12', 'nom' => 'X']]],
            'pas de data' => [fn (User $compte): array => ['success' => false, 'message' => 'Indisponible']],
        ];
    }

    public function test_une_ecole_autonome_n_appelle_jamais_auth_me(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $compte = User::factory()->for($ecole)->create([
            'klassci_token' => null,
            'last_klassci_sync' => now()->subHours(25),
        ]);
        $this->klassciRepond(fn () => $this->formeReelle($compte));

        $this->asTenant($compte)->getJson(self::ROUTE_SYNCHRONISEE);

        self::assertSame(0, $this->appelsAuthMe);
    }

    private function comptePerime(): User
    {
        return $this->compte(['last_klassci_sync' => now()->subHours(25)]);
    }

    /** @param  array<string, mixed>  $attributs */
    private function compte(array $attributs): User
    {
        $ecole = Institution::factory()->create(['klassci_api_url' => self::TENANT_URL]);

        return User::factory()->for($ecole)->create($attributs + [
            'role' => 'enseignant',
            'klassci_role' => 'enseignant',
            'name' => 'Nom Avant',
            'klassci_token' => 'jeton-klassci',
            'klassci_tenant_url' => self::TENANT_URL,
        ]);
    }

    /**
     * Les clés relevées dans le cache de développement, valeurs fictives.
     *
     * @return array<string, mixed>
     */
    private function formeReelle(User $compte, string $nom = 'Nom KLASSCI', string $role = 'enseignant'): array
    {
        return [
            'success' => true,
            'data' => [
                'id' => $compte->klassci_id,
                'nom' => $nom,
                'email' => 'compte@ecole.test',
                'role' => $role,
                'roles' => [$role],
                'avatar' => null,
                'derniere_connexion' => '2026-09-01 08:00:00',
                'preferences' => ['langue' => 'fr', 'timezone' => 'Africa/Abidjan'],
                'contexte_enseignant' => [
                    'nb_matieres' => 1,
                    'nb_classes' => 1,
                    'matieres_principales' => [],
                    'classes_enseignees' => [],
                ],
            ],
            'meta' => [],
        ];
    }

    /**
     * Feinte de la seule frontière réseau, une closure par requête : `auth/me`
     * rend le corps donné et est compté ; tout autre chemin rend une liste vide.
     *
     * @param  \Closure(): array<string, mixed>  $corpsAuthMe
     */
    private function klassciRepond(\Closure $corpsAuthMe): void
    {
        $factory = new HttpFactory;
        $factory->fake(function (ClientRequest $requete) use ($corpsAuthMe) {
            if (! str_contains($requete->url(), 'auth/me')) {
                return Http::response(['success' => true, 'data' => []]);
            }
            $this->appelsAuthMe++;

            return Http::response($corpsAuthMe());
        });

        $this->app->instance(HttpFactory::class, $factory);
    }
}
