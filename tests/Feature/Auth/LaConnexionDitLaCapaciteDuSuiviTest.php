<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use App\Services\Auth\LesCapacitesDuCompte;
use App\Services\Klassci\Auth\KlassciAuthClient;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\Support\OpenApi\OpenApiSchemaValidator;
use Tests\TestCase;

/**
 * #1683 (front#689) : la connexion dit au front si l'école tient ses présences dans le LMS,
 * `meta.peut_suivre_les_presences`. Sans elle, l'écran du suivi s'afficherait dans une école
 * KLASSCI pour n'y rendre que le refus 409 `attendance_managed_by_klassci`.
 *
 * Une CAPACITÉ, jamais le mode (#805) : elle est demandée à la source liée de `SuiviDesPresences`.
 * Comme ses deux voisines, elle porte sur l'école ; le rôle reste jugé par la route (`gestion`).
 */
final class LaConnexionDitLaCapaciteDuSuiviTest extends TestCase
{
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const MOT_DE_PASSE = 'mot-de-passe-1683';

    /** @return array<string, array{InstitutionMode, bool}> */
    public static function volets(): array
    {
        return [
            'école autonome' => [InstitutionMode::Standalone, true],
            'école KLASSCI' => [InstitutionMode::Klassci, false],
        ];
    }

    #[DataProvider('volets')]
    public function test_la_connexion_locale_dit_la_capacite_du_suivi(InstitutionMode $mode, bool $attendu): void
    {
        $email = $this->compteDeGestion($mode)->email;

        $reponse = $this->postJson('/api/auth/login', ['username' => $email, 'password' => self::MOT_DE_PASSE])
            ->assertOk()
            ->assertJsonPath('meta.peut_suivre_les_presences', $attendu);

        $this->assertMatchesOpenApiResponse($reponse, '/auth/login', 'post');
        $this->assertLaSpecExigeLaCapacite((string) $reponse->getContent());
        self::assertArrayNotHasKey('mode', (array) $reponse->json('meta'), 'Le mode n\'est jamais exposé (#805).');
    }

    /** La voie KLASSCI de la connexion porte la même capacité : une école KLASSCI ne suit pas ses présences ici. */
    public function test_la_connexion_klassci_dit_que_le_suivi_n_est_pas_dans_le_lms(): void
    {
        $ecole = Institution::factory()->create([
            'mode' => InstitutionMode::Klassci,
            'slug' => 'presentation',
            'klassci_api_url' => 'https://presentation.klassci.test/api/lms',
            'is_active' => true,
        ]);
        $compte = User::factory()->for($ecole)->create([
            'role' => 'coordinateur',
            'email' => 'gestion@klassci.test',
            'password' => self::MOT_DE_PASSE,
            'klassci_id' => 200001,
            'klassci_token' => 'jeton-expire',
        ]);
        $this->mock(KlassciAuthClient::class, fn ($mock) => $mock->shouldReceive('attemptLogin')->once()->andReturn([
            'data' => ['user' => ['id' => 200001, 'email' => $compte->email, 'nom' => $compte->name, 'role' => 'coordinateur'], 'token' => 'jeton-frais'],
            'meta' => [],
        ]));
        $this->mock(KlassciUserSynchronizer::class, fn ($mock) => $mock->shouldReceive('sync')->once()->andReturn($compte));

        $reponse = $this->postJson('/api/auth/login', ['username' => 'gestion@klassci.test', 'password' => self::MOT_DE_PASSE])
            ->assertOk()
            ->assertJsonPath('meta.klassci_synced', true)
            ->assertJsonPath('meta.peut_suivre_les_presences', false);

        $this->assertMatchesOpenApiResponse($reponse, '/auth/login', 'post');
    }

    /** Les trois capacités sont calculées en un seul endroit ; sans établissement, aucune n'est ouverte. */
    public function test_sans_etablissement_aucune_capacite(): void
    {
        self::assertSame([
            'peut_inscrire_localement' => false,
            'peut_gerer_catalogue_local' => false,
            'peut_suivre_les_presences' => false,
        ], app(LesCapacitesDuCompte::class)->de(null)->versMeta());
    }

    /** Les deux voisines restent servies à l'identique (#805, #1184) : l'objet ne change que leur transport. */
    public function test_les_capacites_voisines_sont_inchangees(): void
    {
        $email = $this->compteDeGestion(InstitutionMode::Standalone)->email;

        $this->postJson('/api/auth/login', ['username' => $email, 'password' => self::MOT_DE_PASSE])
            ->assertOk()
            ->assertJsonPath('meta.peut_inscrire_localement', true)
            ->assertJsonPath('meta.peut_gerer_catalogue_local', true);
    }

    /** Le schéma MORD : la réponse réelle privée de la capacité est refusée (elle est exigée, pas seulement admise). */
    private function assertLaSpecExigeLaCapacite(string $corps): void
    {
        $spec = OpenApiSchemaValidator::depuisFichier(base_path('docs/openapi.yaml'));
        $schema = $spec->schemaDeReponse('/auth/login', 'post', 200);
        self::assertNotNull($schema);
        $sans = json_decode($corps, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $sans);
        self::assertInstanceOf(\stdClass::class, $sans->meta);
        unset($sans->meta->peut_suivre_les_presences);

        self::assertNotSame([], $spec->erreurs($sans, $schema), 'le schéma admet la connexion sans la capacité du suivi');
    }

    private function compteDeGestion(InstitutionMode $mode): User
    {
        $ecole = Institution::factory()->create(['mode' => $mode, 'is_active' => true]);

        // Un compte LOCAL, sans identité KLASSCI : la connexion reste locale, sans réseau.
        return User::factory()->for($ecole)->create([
            'role' => 'coordinateur',
            'email' => 'gestion-'.$mode->value.'@ecole.test',
            'password' => self::MOT_DE_PASSE,
            'klassci_id' => null,
            'klassci_token' => null,
        ]);
    }
}
