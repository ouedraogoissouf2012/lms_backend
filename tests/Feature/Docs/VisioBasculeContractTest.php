<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Seances\Mutations\VisioToggleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Contrat de `POST /lms/seances/{seanceId}/toggle-visio` : chaque réponse
 * RÉELLE suit ce que `docs/openapi.yaml` en dit, statut et corps.
 *
 * Les refus tournent dans les DEUX volets. Le 200, le 422 et le 500 ne
 * tournent que dans le volet KLASSCI : la route rend le 401 à tout compte sans
 * jeton KLASSCI. Dans le volet autonome, le compte est créé SANS jeton, comme
 * tout compte créé dans une école autonome (`UserFactory` en donne un à tout
 * compte). La route ne lit pas le mode de l'école : un compte qui garde son
 * jeton après le passage de son école en autonome n'est pas refusé, ce que la
 * spec dit. Vrai jeton porteur (`asTenant`). KLASSCI est simulé vide : les
 * notifications ne partent pas, la bascule aboutit.
 *
 * @see app/Services/Seances/Mutations/VisioToggleService.php
 */
final class VisioBasculeContractTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/lms/seances/{seanceId}/toggle-visio';

    private const URL = '/api/lms/seances/4242/toggle-visio';

    private const JETON_KLASSCI = 'jeton-klassci-coordinateur';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Http::fake();
    }

    /** @return iterable<string, array{0: bool}> */
    public static function volets(): iterable
    {
        yield 'école KLASSCI' => [false];
        yield 'école autonome' => [true];
    }

    /** @return iterable<string, array{0: string, 1: array<string, mixed>}> */
    public static function bascules(): iterable
    {
        foreach (['coordinateur', 'superAdmin'] as $role) {
            yield "activation, $role" => [$role, ['enabled' => true]];
            yield "activation jitsi explicite, $role" => [$role, ['enabled' => true, 'visio_type' => 'jitsi']];
            yield "désactivation, $role" => [$role, ['enabled' => false]];
        }
    }

    /** @param  array<string, mixed>  $charge */
    #[DataProvider('bascules')]
    public function test_200_une_bascule(string $role, array $charge): void
    {
        $compte = $this->compte($this->ecole(false), $role, self::JETON_KLASSCI);

        $reponse = $this->asTenant($compte)->postJson(self::URL, $charge)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'data');
        $reponse->assertJsonPath('data.visio_enabled', $charge['enabled']);
    }

    /**
     * Une séance déjà activée, puis désactivée et supprimée en douceur : la mise
     * à jour et la restauration rendent le même contrat que la création.
     */
    public function test_200_une_seance_existante_puis_supprimee(): void
    {
        $compte = $this->compte($this->ecole(false), 'coordinateur', self::JETON_KLASSCI);
        $this->asTenant($compte)->postJson(self::URL, ['enabled' => true])->assertOk();

        $miseAJour = $this->asTenant($compte)->postJson(self::URL, ['enabled' => true])->assertOk();
        $this->assertMatchesOpenApiResponse($miseAJour, self::CHEMIN, 'post');

        Seance::query()->withoutGlobalScopes()->where('klassci_seance_id', 4242)->firstOrFail()->delete();

        $restauration = $this->asTenant($compte)->postJson(self::URL, ['enabled' => false])->assertOk();
        $this->assertMatchesOpenApiResponse($restauration, self::CHEMIN, 'post');
        self::assertSame(1, Seance::query()->withoutGlobalScopes()->count());
    }

    /** Un compte sans jeton KLASSCI : le 401 motivé de #806, et aucune séance n'est créée. */
    #[DataProvider('volets')]
    public function test_401_sans_jeton_klassci(bool $autonome): void
    {
        $compte = $this->compte($this->ecole($autonome), 'coordinateur', null);

        $reponse = $this->asTenant($compte)->postJson(self::URL, ['enabled' => true])->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertExactJson(MissingKlassciTokenException::payload());
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->count());
    }

    public function test_401_sans_jeton_porteur(): void
    {
        $reponse = $this->postJson(self::URL, ['enabled' => true])->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
    }

    /** @return iterable<string, array{0: bool, 1: string}> */
    public static function rolesRefuses(): iterable
    {
        foreach (self::volets() as $volet => [$autonome]) {
            yield "enseignant, $volet" => [$autonome, 'enseignant'];
            yield "étudiant, $volet" => [$autonome, 'etudiant'];
        }
    }

    #[DataProvider('rolesRefuses')]
    public function test_403_un_role_non_admis(bool $autonome, string $role): void
    {
        $compte = $this->compte($this->ecole($autonome), $role, $autonome ? null : self::JETON_KLASSCI);

        $reponse = $this->asTenant($compte)->postJson(self::URL, ['enabled' => true])->assertForbidden();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'message');
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->count());
    }

    public function test_403_un_compte_sans_etablissement(): void
    {
        $compte = User::factory()->create(['institution_id' => null, 'role' => 'coordinateur', 'klassci_token' => self::JETON_KLASSCI]);

        $reponse = $this->asTenant($compte)->postJson(self::URL, ['enabled' => true])->assertForbidden();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'message');
        $reponse->assertJsonPath('reason', 'no_institution');
    }

    public function test_403_un_etablissement_desactive(): void
    {
        $ecole = Institution::factory()->create(['is_active' => false]);
        $compte = $this->compte($ecole, 'coordinateur', self::JETON_KLASSCI);

        $reponse = $this->asTenant($compte)->postJson(self::URL, ['enabled' => true])->assertForbidden();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertJsonMissingPath('reason');
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->count());
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function chargesInvalides(): iterable
    {
        yield 'corps vide' => [[]];
        yield 'enabled non booléen' => [['enabled' => 'oui']];
        yield 'fournisseur non câblé' => [['enabled' => true, 'visio_type' => 'zoom']];
    }

    /** @param  array<string, mixed>  $charge */
    #[DataProvider('chargesInvalides')]
    public function test_422_une_charge_invalide(array $charge): void
    {
        $compte = $this->compte($this->ecole(false), 'coordinateur', self::JETON_KLASSCI);

        $reponse = $this->asTenant($compte)->postJson(self::URL, $charge)->assertUnprocessable();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'errors');
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->count());
    }

    /**
     * Une erreur dans la bascule rend le 500 décrit. Elle est provoquée par le
     * journal du service, dont le premier appel précède toute écriture.
     */
    public function test_500_une_erreur_interne(): void
    {
        $this->app->when(VisioToggleService::class)->needs(LoggerInterface::class)->give(
            static fn (): LoggerInterface => new class extends AbstractLogger
            {
                public function log($level, string|Stringable $message, array $context = []): void
                {
                    if ($level === 'info') {
                        throw new RuntimeException('panne provoquée par le test');
                    }
                }
            },
        );
        $compte = $this->compte($this->ecole(false), 'coordinateur', self::JETON_KLASSCI);

        $reponse = $this->asTenant($compte)->postJson(self::URL, ['enabled' => true])->assertStatus(500);

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'message');
        $reponse->assertJsonPath('error', 'Une erreur est survenue.');
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->count());
    }

    /** Le 500 du gestionnaire global, sans `error` : la spec admet les deux formes. */
    public function test_500_du_gestionnaire_global_sans_error(): void
    {
        $compte = $this->compte($this->ecole(false), 'coordinateur', self::JETON_KLASSCI);

        $reponse = $this->asTenant($compte)->postJson('/api/lms/seances/abc/toggle-visio', ['enabled' => true])->assertStatus(500);

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertJsonMissingPath('error');
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->count());
    }

    private function ecole(bool $autonome): Institution
    {
        return $autonome ? Institution::factory()->standalone()->create() : Institution::factory()->create();
    }

    private function compte(Institution $ecole, string $role, ?string $jetonKlassci): User
    {
        return User::factory()->create(['institution_id' => $ecole->id, 'role' => $role, 'klassci_token' => $jetonKlassci]);
    }
}
